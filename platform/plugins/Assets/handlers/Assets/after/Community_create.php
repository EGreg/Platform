<?php

function Assets_after_Community_create($params)
{
	$userId = $params['userId'];
	$community = $params['community'];
	$skipAccess = $params['skipAccess'];
	$quota = $params['quota'];

	// if for some reason skippAccess or quota not exceeded
	if ($skipAccess || $quota instanceof Users_Quota) {
		return;
	}

	$amountToSpend = (int)Q_Config::expect('Assets', 'credits', 'amounts', 'createCommunity');

	Assets_Credits::spend($amountToSpend, $userId, array(
		'reason' => "Created community ".$community->id,
		'publisherId' => $community->id,
		'streamName' => 'Streams/experience/main'
	));
}