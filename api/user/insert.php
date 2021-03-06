<?php

if( !defined('PROPER_START') )
{
	header("HTTP/1.0 403 Forbidden");
	exit;
}

$a = new action();
$a->addAlias(array('create', 'add'));
$a->setDescription("Creates a new user");
$a->addGrant(array('ACCESS', 'USER_INSERT'));
$a->setReturn(array(array(
	'id'=>'the id of the user', 
	'name'=>'the user login'
	)));
$a->addParam(array(
	'name'=>array('name', 'user_name', 'username', 'login', 'user'),
	'description'=>'The name of the new user.',
	'optional'=>false,
	'minlength'=>3,
	'maxlength'=>50,
	'match'=>request::LOWER|request::NUMBER|request::PUNCT,
	'action'=>true
	));
$a->addParam(array(
	'name'=>array('pass', 'password', 'user_password', 'user_pass'),
	'description'=>'The password of the user.',
	'optional'=>false,
	'minlength'=>6,
	'maxlength'=>50,
	'match'=>request::PHRASE|request::SPECIAL,
	'action'=>true
	));
$a->addParam(array(
	'name'=>array('firstname', 'givenname', 'first_name', 'user_firstname', 'user_givenname', 'user_first_name', 'user_given_name'),
	'description'=>'The first name of the new user.',
	'optional'=>true,
	'minlength'=>0,
	'maxlength'=>50,
	'match'=>request::PHRASE
	));
$a->addParam(array(
	'name'=>array('lastname', 'sn', 'user_lastname', 'user_sn', 'user_last_name'),
	'description'=>'The last name of the user.',
	'optional'=>true,
	'minlength'=>0,
	'maxlength'=>50,
	'match'=>request::PHRASE
	));
$a->addParam(array(
	'name'=>array('mail', 'email', 'address', 'user_email', 'user_mail', 'user_address'),
	'description'=>'The email of the user.',
	'optional'=>true,
	'minlength'=>0,
	'maxlength'=>150,
	'match'=>"^[_\\w\\.-]+@[a-zA-Z0-9\\.-]{1,100}\\.[a-zA-Z0-9]{2,6}$"
	));
$a->addParam(array(
	'name'=>array('ip'),
	'description'=>'IP address of the user.',
	'optional'=>true,
	'minlength'=>0,
	'maxlength'=>50,
	'match'=>request::ALL
	));
$a->addParam(array(
	'name'=>array('language', 'lang'),
	'description'=>'The user language.',
	'optional'=>true,
	'minlength'=>1,
	'maxlength'=>2,
	'match'=>request::UPPER
	));

$a->setExecute(function() use ($a)
{
	// =================================
	// CHECK AUTH
	// =================================
	$a->checkAuth();

	// =================================
	// GET PARAMETERS
	// =================================
	$user = $a->getParam('user');
	$pass = $a->getParam('pass');
	$firstname = $a->getParam('firstname');
	$lastname = $a->getParam('lastname');
	$mail = $a->getParam('mail');
	$ip = $a->getParam('ip');
	$language = $a->getParam('language');
	
	if( is_numeric($user) )
		throw new ApiException("Parameter validation failed", 412, "Parameter user may not be numeric : " . $user);

	// =================================
	// CHECK IF LOCAL USER EXISTS
	// =================================
	$sql = "SELECT user_id FROM users WHERE user_name = '".security::escape($user)."'";
	$result = $GLOBALS['db']->query($sql);

	if( $result !== null || $result['user_id'] !== null )
		throw new ApiException("User already exists", 412, "Existing local user : " . $user);

	// =================================
	// CHECK IF REMOTE USER EXISTS
	// =================================
	try
	{
		$dn = ldap::buildDN(ldap::USER, $GLOBALS['CONFIG']['DOMAIN'], $user);
		$result = $GLOBALS['ldap']->read($dn);
		
		// this should throw a 404 if the user does NOT exist
		throw new ApiException("User already exists", 412, "Existing remote user : " . $user);
	}
	catch(Exception $e)
	{
		// if this is not the 404 we expect, rethrow it
		if( !($e instanceof ApiException) || !preg_match("/Entry not found/s", $e.'') )
			throw $e;
	}
	
	// =================================
	// CHECK FOR USER WITH THE SAME EMAIL
	// =================================		
	$result = $GLOBALS['ldap']->search($GLOBALS['CONFIG']['LDAP_BASE'], ldap::buildFilter(ldap::USER, "(mailForwardingAddress={$mail})"));
	
	if( count($result) > 0 )
		throw new ApiException("Email already exists", 412, "Existing email : {$mail}");

	// =================================
	// INSERT REMOTE USER
	// =================================
	$dn = ldap::buildDN(ldap::USER, $GLOBALS['CONFIG']['DOMAIN'], $user);
	$params = array('dn' => $dn, 'uid' => $user, 'userPassword' => $pass, 'domain' => $GLOBALS['CONFIG']['DOMAIN']);
	
	if( $firstname !== null )
		$params['givenName'] = $firstname;
	if( $lastname !== null )
		$params['sn'] = $lastname;
	if( $mail !== null )
		$params['mailForwardingAddress'] = $mail;
	if( $ip !== null )
		$params['ipHostNumber'] = security::escape($ip);
	if( $language !== null )
		$params['gecos'] = $language;
		
	$handler = new user();
	$data = $handler->build($params);
	
	$result = $GLOBALS['ldap']->create($dn, $data);
	
	// =================================
	// INSERT LOCAL USER
	// =================================
	$sql = "INSERT INTO users (user_name, user_ldap, user_date) VALUES ('".security::escape($user)."', {$data['uidNumber']}, ".time().")";
	$GLOBALS['db']->query($sql, mysql::NO_ROW);
	$uid = $GLOBALS['db']->last_id();
	
	// =================================
	// INSERT PIWIK USER
	// =================================
	$url = "https://{$GLOBALS['CONFIG']['PIWIK_URL']}/index.php?module=API&method=UsersManager.addUser&userLogin={$user}&password={$pass}&email={$user}@{$GLOBALS['CONFIG']['DOMAIN']}&format=JSON&token_auth={$GLOBALS['CONFIG']['PIWIK_TOKEN']}";
	@file_get_contents($url);

	// =================================
	// POST-CREATE SYSTEM ACTIONS
	// =================================
	$data['domain'] = $GLOBALS['CONFIG']['DOMAIN'];
	$command = "mkdir -p {$data['homeDirectory']} && chown 33:{$data['gidNumber']} {$data['homeDirectory']} && chmod 770 {$data['homeDirectory']} && chmod g+s {$data['homeDirectory']}";
	$GLOBALS['gearman']->sendAsync($command);
	
	// =================================
	// LOG ACTION
	// =================================	
	logger::insert('user/insert', $a->getParams(), $uid);
	
	responder::send(array("name"=>$user, "id"=>$uid));
});

return $a;

?>
