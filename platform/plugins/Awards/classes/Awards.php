<?php
/**
 * Awards model
 * @module Awards
 * @main Awards
 */
/**
 * Static methods for the Awards models.
 * @class Awards
 * @extends Base_Awards
 */

require __DIR__ . '/Composer/vendor/autoload.php';

abstract class Awards extends Base_Awards
{
	/**
	 * @method giveAward
	 * @static
	 * @param  {string}  $awardName
	 * @param  {string}  $userId
	 * @param  {string}  [$associated_id='']
	 * @param  {boolean} [$duplicate=true]
	 */
	static function giveAward($awardName, $userId, $associated_id='', $duplicate=true)
	{
		if (!$duplicate and self::hasAward($awardName, $userId)) {
			return;
		}
		$earnedBadge = new Awards_Earned();
		$earnedBadge->app = Q_Config::expect('Q', 'app');
		$earnedBadge->badge_name = $awardName;
		$earnedBadge->userId = $userId;
		$earnedBadge->associated_id = $associated_id;
		$earnedBadge->save();
		$earnedBadge = null;
	}

	/**
	 * @method hasAward
	 * @static
	 * @param  {string}  $awardName
	 * @param  {string}  $userId
	 * @return {boolean}
	 */
	static function hasAward($awardName, $userId)
	{
		$exists = new Awards_Earned();
		$exists->app = Q_Config::expect('Q', 'app');
		$exists->userId = $userId;
		$exists->badge_name = $awardName;
		return $exists->retrieve()? true : false;
	}

	/**
	 * @method getAwards
	 * @static
	 * @param $userId {string} User ID
	 * @param  {string|boolean} [$app=false] Application name
	 * @return {array}
	 */
	static function getAwards($userId, $app=false)
	{
		$whereArray = array('ern.userId'=>$userId);
		if($app !== false){
			$whereArray['ern.app'] = $app;
		}

		$awardsEarned = Awards_Earned::select('ern.*,  bdg.pic_small, bdg.pic_big, bdg.title, bdg.points', 'ern')
				->join(Awards_Badge::table().' AS bdg', array('ern.badge_name'=>'bdg.name', 'ern.app'=>'bdg.app'))
				->where($whereArray)
				->fetchDbRows();

		return $awardsEarned;
	}

	/**
	 * Calculates award leaders and update table
	 * @method calculateLeaders
	 * @static
	 * @param {string} $app
	 * @return {boolean}
	 */
	static function calculateLeaders($app)
	{
		$leaders = Awards_Earned::select('ern.app AS app, CURDATE() AS day_calculated, ern.userId AS userId, SUM(bdg.points) AS points', 'ern')
				->join(Awards_Badge::table().' AS bdg', array('ern.badge_name'=>'bdg.name', 'ern.app'=>'bdg.app'))
				->where('ern.app="'.$app.'" AND ern.insertedTime>=ADDDATE(CURDATE(), INTERVAL -7 DAY) AND ern.insertedTime<=CURDATE()')
				->groupBy('ern.userId')
				->fetchDbRows();

		if(!empty($leaders)){
			foreach($leaders as $leader){
				$awardsLeader = new Awards_Leader();
				$awardsLeader->app = $leader->app;
				$awardsLeader->day = $leader->day_calculated;

				$result = $awardsLeader->retrieve();
				if(!empty($result)) return false;

				$awardsLeader->userId = $leader->userId;
				$awardsLeader->points = $leader->points;
				$awardsLeader->save();
			}
			$awardsLeader = null;
			return true;
		}else{
			return false;
		}
	}

	/**
	 * Retrieves total points for user
	 * @method getTotalPoints
	 * @static
	 * @param $userId {string} User ID
	 * @param $app {string} Application name
	 * @return {integer|false} Total points
	 */
	static function getTotalPoints($userId, $app)
	{
		$whereArray = array('ern.userId'=>$userId, 'ern.app'=>$app);

		$totalPoints = Awards_Earned::select('SUM(bdg.points) AS total_points', 'ern')
				->join(Awards_Badge::table().' AS bdg', array('ern.badge_name'=>'bdg.name', 'ern.app'=>'bdg.app'))
				->where($whereArray)
				->fetchDbRows();
		if(!empty($totalPoints)){
			return $totalPoints[0]->total_points;
		}else{
			return false;
		}
	}

	/**
	 * Makes a one-time charge on a customer account using a payments processor
	 * @method charge
	 * @static
	 * @param {string} $payments The type of payments processor, could be "Authnet" or "Stripe"
	 * @param {string} $amount specify the amount
	 * @param {string} [$currency='USD'] set the currency, which will affect the amount
	 * @param {array} [$options=array()] Any additional options
 	 * @param {Users_User} [$options.user=Users::loggedInUser()] Allows us to set the user to charge
	 * @param {Streams_Stream} [$options.stream=null] if this charge is related to an Awards/product, Awards/service or Awards/subscription stream
	 * @param {string} [$options.token=null] required for stripe unless the user is an existing customer
	 * @param {string} [$options.description=null] description of the charge, to be sent to customer
	 * @param {string} [$options.metadata=null] any additional metadata to store with the charge
	 * @throws \Stripe\Error\Card
	 * @throws Awards_Exception_DuplicateTransaction
	 * @throws Awards_Exception_HeldForReview
	 * @throws Awards_Exception_ChargeFailed
	 * @return {Awards_Charge} the saved database row corresponding to the charge
	 */
	static function charge($payments, $amount, $currency = 'USD', $options = array())
	{
		$currency = strtoupper($currency);
		$user = Q::ifset($options, 'user', Users::loggedInUser(true));
		$className = 'Awards_Payments_' . ucfirst($payments);
		$adapter = new $className($options);
		$communityId = Users::communityId();
		/**
		 * @event Awards/charge {before}
		 * @param {Awards_Payments} adapter
		 * @param {array} options
		 */
		Q::event('Awards/charge', compact('adapter', 'options'), 'before');
		$customerId = $adapter->charge($amount, $currency, $options);
		$charge = new Awards_Charge();
		$charge->userId = $user->id;
		$charge->publisherId = Q::ifset($options, 'stream', 'publisherId', '');
		$charge->streamName = Q::ifset($options, 'stream', 'name', '');
		$charge->description = Q::ifset($options, 'description', '');
		$attributes = array(
			"payments" => $payments,
			"customerId" => $customerId,
			"amount" => sprintf("%0.2f", $amount),
			"currency" => $currency,
			"communityId" => $communityId
		);
		$charge->attributes = Q::json_encode($attributes);
		$charge->save();
		/**
		 * @event Awards/charge {after}
		 * @param {Awards_charge} charge
		 * @param {Awards_Payments} adapter
		 * @param {array} options
		 */
		Q::event('Awards/charge', compact(
			'payments', 'amount', 'currency', 'user', 'charge', 'adapter', 'options'
		), 'after');

		return $charge;
	}

	/**
	 * Starts a recurring subscription
	 * @param {Streams_Stream} $plan The subscription plan stream
	 * @param {string} [$payments=null] The type of payments processor, could be "authnet" or "stripe". If omitted, the subscription proceeds without any payments.
	 * @param {array} [$options=array()] Options for the subscription
	 * @param {date} [$options.startDate=today] The start date of the subscription
	 * @param {date} [$options.endDate=today+year] The end date of the subscription
 	 * @param {Users_User} [$options.user=Users::loggedInUser()] Allows us to set the user to subscribe
	 * @param {Users_User} [$options.publisherId=Users::communityId()] Allows us to override the publisher to subscribe to
	 * @param {string} [$options.description=null] description of the charge, to be sent to customer
	 * @param {string} [$options.metadata=null] any additional metadata to store with the charge
	 * @param {string} [$options.subscription=null] if this charge is related to a subscription stream
	 * @param {string} [$options.subscription.publisherId]
	 * @param {string} [$options.subscription.streamName]
	 * @throws Awards_Exception_DuplicateTransaction
	 * @throws Awards_Exception_HeldForReview
	 * @throws Awards_Exception_ChargeFailed
	 * @return {Streams_Stream} A stream of type 'Awards/subscription' representing this subscription
	 */
	static function startSubscription($plan, $payments = null, $options = array())
	{
		if (!isset($options['user'])) {
			$options['user'] = Users::loggedInUser(true);
		}
		
		$app = Q_Config::expect('Q', 'app');
		$user = Q::ifset($options, 'user', Users::loggedInUser(true));
		$currency = 'USD'; // TODO: may want to implement support for currency conversion
		$startDate = Q::ifset($options, 'startDate', date("Y-m-d"));
		$startDate = date('Y-m-d', strtotime($startDate));
		$months = $plan->getAttribute('months', 12);
		$amount = $plan->getAttribute('amount');
		$endDate = date("Y-m-d", strtotime("-1 day", strtotime("+$months month", strtotime($startDate))));
		$endDate = date('Y-m-d', strtotime($endDate));

		$publisherId = Q::ifset($options, 'publisherId', Users::communityId());
		$publisher = Users_User::fetch($publisherId);
		$streamName = "Awards/subscription/{$user->id}/{$plan->name}";
		if ($subscription = Streams::fetchOne($publisherId, $publisherId, $streamName)) {
			return $subscription; // it already started
		}
		$attributes = Q::json_encode(array(
			'payments' => $payments,
			'planPublisherId' => $plan->publisherId,
			'planStreamName' => $plan->name,
			'startDate' => $startDate,
			'endDate' => $endDate,
			'months' => $months,
			'amount' => $amount, // lock it in, in case plan changes later
			'currency' => $currency
		));
		$stream = Streams::create(
			$publisherId,
			$publisherId,
			"Awards/subscription",
			array(
				'name' => $streamName,
				'title' => $plan->title,
				'readLevel' => Streams::$READ_LEVEL['none'],
				'writeLevel' => Streams::$WRITE_LEVEL['none'],
				'adminLevel' => Streams::$ADMIN_LEVEL['none'],
				'attributes' => $attributes
			)
		);
		$access = new Streams_Access(array(
			'publisherId' => $publisherId,
			'streamName' => $streamName,
			'ofUserId' => $user->id,
			'grantedByUserId' => $app,
			'readLevel' => Streams::$READ_LEVEL['max'],
			'writeLevel' => -1,
			'adminLevel' => -1
		));
		$access->save();
		$amount = $plan->getAttribute('amount', null);
		if (!is_numeric($amount)) {
			throw new Q_Exception_WrongValue(array(
				'field' => 'amount',
				'range' => 'an integer'
			));
		}
		$options['stream'] = $stream;
		if ($payments) {
			Awards::charge($payments, $amount, $currency, $options);
		}
		
		/**
		 * @event Awards/startSubscription {before}
		 * @param {Streams_Stream} plan
		 * @param {Streams_Stream} subscription
		 * @param {string} startDate
		 * @param {string} endDate
		 * @return {Users_User}
		 */
		Q::event('Awards/startSubscription', compact(
			'plan', 'user', 'publisher', 'stream', 'startDate', 'endDate', 'months', 'currency'
		), 'after');

		return $stream;
	}

	static function stopSubscription($stream, $options = array())
	{
		// call this if we learn that a subscription has stopped, so we mark it as inactive.
		// the customer could use the credit card info to start a new subscription.
		$stream->setAttribute('stopped', true);
		$stream->save();
	}

	/* * * */
};