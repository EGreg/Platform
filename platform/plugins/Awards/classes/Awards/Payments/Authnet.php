<?php
/**
 * @module Awards
 */
/**
 * Adapter implementing Authorize.net support for Awards_Payments functions
 *
 * @class Awards_Payments_Authnet
 * @implements Awards_Payments
 */

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\util\LogFactory as LogFactory;

class Awards_Payments_Authnet extends Awards_Payments implements iAwards_Payments
{
	/**
	 * @constructor
	 * @param {array} [$options=array()] Any initial options
 	 * @param {Users_User} [$options.user=Users::loggedInUser()] Allows us to set the user to charge
	 * @param {string} [$options.authname] Optionally override the authname from config
	 * @param {string} [$options.authkey] Optionally override the authkey from config
	 */
	function __construct($options = array())
	{
		Q::includeFile(implode(DS, array(
			Q_PLUGINS_DIR, 'Awards', 'classes', 'Composer', 'vendor', 'autoload.php'
		)));
		$testing = Q_Config::expect('Awards', 'payments', 'authnet', 'testing');
		$server = $testing
			? net\authorize\api\constants\ANetEnvironment::SANDBOX
			: net\authorize\api\constants\ANetEnvironment::PRODUCTION;
		if (!isset($options['user'])) {
			$options['user'] = Users::loggedInUser(true);
		}
		$this->options = array_merge(array(
			'authname' => Q_Config::expect('Awards', 'payments', 'authnet', 'name'),
			'authkey' => Q_Config::expect('Awards', 'payments', 'authnet', 'transactionKey'),
			'server' => $server
		), $options);
	}
	
	/**
	 * Executes some API calls and obtains a customer id
	 * @method customerId
	 * @return {string} The customer id
	 */
	function customerId()
	{
		$options = $this->options;

		// Common Set Up for API Credentials
		$merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
		$merchantAuthentication->setName($options['authname']);
		$merchantAuthentication->setTransactionKey($options['authkey']);
		$refId = 'ref' . time();

		$user = $options['user'];
		$merchantCustomerId = $user->id;
		
		$c = new Awards_Customer();
		$c->userId = $user->id;
		$c->payments = 'authnet';
		if ($c->retrieve()) {
			return $c->customerId;
		}

		$customerprofile = new AnetAPI\CustomerProfileType();
		$customerprofile->setMerchantCustomerId($merchantCustomerId);
		$customerprofile->setDescription($user->displayName());
		$customerprofile->setEmail($user->emailAddress);

		$request = new AnetAPI\CreateCustomerProfileRequest();
		$request->setMerchantAuthentication($merchantAuthentication);
		$request->setRefId($refId);
		$request->setProfile($customerprofile);

		$controller = new AnetController\CreateCustomerProfileController($request);

		$response = $controller->executeWithApiResponse($options['server']);

		if ($response != null && $response->getMessages()->getResultCode() == "Ok") {
			return $response->getCustomerProfileId();
		}
		
		if ($response != null && $response->getMessages()->getResultCode() == "Ok") {
			$customerId = $response->getCustomerProfileId();
		} else {
			$messages = $response->getMessages()->getMessage();
			$message = reset($messages);
		
			// workaround to get customerProfileId
			// https://community.developer.authorize.net/t5/Integration-and-Testing/How-to-lookup-customerProfileId-and-paymentProfileId-by/td-p/52501
			if (isset($response) and ($message->getCode() != "E00039")) {
				throw new Awards_Exception_InvalidResponse(array(
					'response' => $message->getCode() . ' ' . $message->getText()
				));
			}
			$parts = explode(' ', $message->getText());
			$customerId = $parts[5];
		}
		$c->customerId = $customerId;
		$c->save();
		return $customerId;
	}
	
	/**
	 * Executes some API calls and obtains a payment profile id
	 * @method paymentProfileId
	 * @return {string} The payment profile id
	 */
	function paymentProfileId($customerId)
	{
		$options = $this->options;
		
		$merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
		$merchantAuthentication->setName($options['authname']);
		$merchantAuthentication->setTransactionKey($options['authkey']);

		$request = new AnetAPI\GetCustomerProfileRequest();
		$request->setMerchantAuthentication($merchantAuthentication);
		$request->setCustomerProfileId($customerId);
		$controller = new AnetController\GetCustomerProfileController($request);
		$response = $controller->executeWithApiResponse($options['server']);
		if (!isset($response) or $response->getMessages()->getResultCode() != "Ok") {
			$messages = $response->getMessages()->getMessage();
			$message = reset($messages);
			throw new Awards_Exception_InvalidResponse(array(
				'response' => $message->getCode() . ' ' . $message->getText()
			));
		}
		$profileSelected = $response->getProfile();
		$paymentProfilesSelected = $profileSelected->getPaymentProfiles();
		if ($paymentProfilesSelected == null) {
			throw new Awards_Exception_PaymentMethodRequired();
		}
		return $paymentProfilesSelected[0]->getCustomerPaymentProfileId();
	}
	
	/**
	 * Make a one-time charge using the payments processor
	 * @method charge
	 * @param {double} $amount specify the amount (optional cents after the decimal point)
	 * @param {string} [$currency='USD'] set the currency, which will affect the amount
	 * @param {array} [$options=array()] Any additional options
	 * @param {string} [$options.description=null] description of the charge, to be sent to customer
	 * @param {string} [$options.metadata=null] any additional metadata to store with the charge
	 * @param {string} [$options.subscription=null] if this charge is related to a subscription stream
	 * @param {string} [$options.subscription.publisherId]
	 * @param {string} [$options.subscription.streamName]
	 * @throws Awards_Exception_DuplicateTransaction
	 * @throws Awards_Exception_HeldForReview
	 * @throws Awards_Exception_ChargeFailed
	 * @return {Awards_Charge} the saved database row corresponding to the charge
	 */
	function charge($amount, $currency = 'USD', $options = array())
	{
		$customerId = $this->customerId();
		$paymentProfileId = $this->paymentProfileId($customerId);
		
		$options = array_merge($this->options, $options);
		$user = $options['user'];
		
		// Common setup for API credentials
		$merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
		$merchantAuthentication->setName($options['authname']);
		$merchantAuthentication->setTransactionKey($options['authkey']);
		$refId = 'ref' . time();

		$paymentProfile = new AnetAPI\PaymentProfileType();
		$paymentProfile->setPaymentProfileId($paymentProfileId);

		$profileToCharge = new AnetAPI\CustomerProfilePaymentType();
		$profileToCharge->setCustomerProfileId($customerId);
		$profileToCharge->setPaymentProfile($paymentProfile);

		$transactionRequestType = new AnetAPI\TransactionRequestType();
		$transactionRequestType->setTransactionType("authCaptureTransaction");
		$transactionRequestType->setAmount($amount);
		$transactionRequestType->setProfile($profileToCharge);

		$request = new AnetAPI\CreateTransactionRequest();
		$request->setMerchantAuthentication($merchantAuthentication);
		$request->setRefId($refId);
		$request->setTransactionRequest($transactionRequestType);
		$controller = new AnetController\CreateTransactionController($request);

		$response = $controller->executeWithApiResponse($options['server']);
		if (!isset($response)) {
			throw new Awards_Exception_InvalidResponse(array(
				'response' => 'empty response'
			));
		}
		
		$tresponse = $response->getTransactionResponse();
		if (!isset($tresponse)) {
			throw new Awards_Exception_ChargeFailed();
		}
		switch ($tresponse->getResponseCode()) {
		case '1':
			$charge = new Awards_Charge();
			$charge->userId = $user->id;
			$charge->subscriptionPublisherId = Q::ifset($options, 'subscription', 'publisherId', '');
			$charge->subscriptionStreamName = Q::ifset($options, 'subscription', 'streamName', '');
			$charge->description = Q::ifset($options, 'description', '');
			$charge->attributes = Q::json_encode(array(
				"amount" => $amount,
				"currency" => $currency,
				"customerId" => $customerId
			));
			$charge-save();
			return $charge;
		case '3': 
			throw new Awards_Exception_DuplicateTransaction();
		case '4':
			throw new Awards_Exception_HeldForReview();
		default:
			throw new Awards_Exception_ChargeFailed();
		}
	}
	
	function authToken($customerId = null)
	{
		if (!isset($customerId)) {
			$customerId = $this->customerId();
		}
		
		$options = $this->options;

		// Common Set Up for API Credentials
		$merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
		$merchantAuthentication->setName($options['authname']);
		$merchantAuthentication->setTransactionKey($options['authkey']);
		$refId = 'ref' . time();

		$setting = new AnetAPI\SettingType();

		// 2do: fix domain name and path for iframe popup

		$setting->setSettingName("hostedProfileIFrameCommunicatorUrl");
		$setting->setSettingValue(Q_Html::themedUrl('plugins/Awards/authnet_iframe_communicator.html'));

		$setting->setSettingName("hostedProfilePageBorderVisible");
		$setting->setSettingValue("false");

		$frequest = new AnetAPI\GetHostedProfilePageRequest();
		$frequest->setMerchantAuthentication($merchantAuthentication);
		$frequest->setCustomerProfileId($customerId);
		$frequest->addToHostedProfileSettings($setting);

		$controller = new AnetController\GetHostedProfilePageController($frequest);
		$fresponse = $controller->executeWithApiResponse($options['server']);

		if (!isset($fresponse) or ($fresponse->getMessages()->getResultCode() != "Ok")) {
			$messages = $fresponse->getMessages()->getMessage();
			$message = reset($messages);
			throw new Awards_Exception_InvalidResponse(array(
				'response' => $message->getCode() . ' ' . $message->getText()
			));
		}
		return $fresponse->getToken();
	}
	
	public $options = array();

}