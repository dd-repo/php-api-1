<?php

if( !defined('PROPER_START') )
{
	header("HTTP/1.0 403 Forbidden");
	exit;
}

$a = new action();
$a->addAlias(array('list', 'view', 'search'));
$a->setDescription("Searches for a site");
$a->addGrant(array('ACCESS', 'SITE_SELECT'));
$a->setReturn(array(array(
	'name'=>'the site name', 
	'id'=>'the id of the site', 
	'hostname'=>'the complete site hostname', 
	'homeDirectory'=>'the directory of the site',
	'cNAMERecord'=>'the CNAME Record of the site',
	'aRecord'=>'the aRecord of the site',
	'user'=>array(array(
		'id'=>'the user id', 
		'name'=>'the username')
	),
	)));
$a->addParam(array(
	'name'=>array('site', 'site_id', 'id'),
	'description'=>'The name or id of the target site.',
	'optional'=>true,
	'minlength'=>0,
	'maxlength'=>50,
	'match'=>request::LOWER|request::NUMBER|request::PUNCT,
	'action'=>false
	));
$a->addParam(array(
	'name'=>array('user', 'user_name', 'username', 'login', 'user_id', 'uid'),
	'description'=>'The name or id of the target user.',
	'optional'=>true,
	'minlength'=>0,
	'maxlength'=>30,
	'match'=>request::LOWER|request::NUMBER|request::PUNCT,
	'action'=>false
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
	$site = $a->getParam('site');
	$user = $a->getParam('user');

	// =================================
	// GET USER DATA
	// =================================
	if( $user !== null )
	{ 
		$sql = "SELECT user_ldap FROM users u WHERE ".(is_numeric($user)?"u.user_id=".$user:"u.user_name = '".security::escape($user)."'");
		$userdata = $GLOBALS['db']->query($sql);
		if( $userdata == null || $userdata['user_ldap'] == null )
			throw new ApiException("Unknown user", 412, "Unknown user : {$user}");
	}

	// =================================
	// SELECT REMOTE ENTRIES
	// =================================
	if( $site !== null )
	{
		if( is_numeric($site) )
			$dn = $GLOBALS['ldap']->getDNfromUID($site);
		else
			$dn = ldap::buildDN(ldap::SUBDOMAIN, $GLOBALS['CONFIG']['DOMAIN'], $site);
		
		$result = $GLOBALS['ldap']->read($dn);
	}
	else if( $user !== null )
	{
		$user_dn = $GLOBALS['ldap']->getDNfromUID($userdata['user_ldap']);
		$result = $GLOBALS['ldap']->search(ldap::buildDN(ldap::DOMAIN, $GLOBALS['CONFIG']['DOMAIN']), ldap::buildFilter(ldap::SUBDOMAIN, "(owner={$user_dn})"));
	}
	else
		$result = $GLOBALS['ldap']->search($GLOBALS['CONFIG']['LDAP_BASE'], ldap::buildFilter(ldap::SUBDOMAIN));

	// =================================
	// FORMAT RESULT
	// =================================
	$sites = array();
	if( $site !== null )
	{
		if( is_array($result['owner']) )
			$result['owner'] = $result['owner'][0];
			
		if( $user !== null && $GLOBALS['ldap']->getUIDfromDN($result['owner']) != $userdata['user_ldap'] )
			throw new ApiException("Forbidden", 403, "User {$user} ({$userdata['user_ldap']}) does not match owner of the site {$site} ({$result['gidNumber']})");
		
		$sql = "SELECT user_id, user_name FROM users WHERE user_ldap = ".$GLOBALS['ldap']->getUIDfromDN($result['owner']);
		$info = $GLOBALS['db']->query($sql);
		
		$s['name'] = $result['uid'];
		$s['id'] = $result['uidNumber'];
		$s['hostname'] = $result['associatedDomain'];
		$s['homeDirectory'] = $result['homeDirectory'];
		$s['cNAMERecord'] = $result['cNAMERecord'];
		$s['aRecord'] = $result['aRecord'];
		$s['user'] = array('id'=>$info['user_id'], 'name'=>$info['user_name']);
		
		$sites[] = $s;
	}
	else
	{
		foreach( $result as $r )
		{
			$s['name'] = $r['uid'];
			$s['id'] = $r['uidNumber'];
			$s['hostname'] = $r['associatedDomain'];
			$s['homeDirectory'] = $r['homeDirectory'];
			$s['cNAMERecord'] = $r['cNAMERecord'];
			$s['aRecord'] = $r['aRecord'];
			$s['user'] = array('id'=>'', 'name'=>'');
			
			$sites[] = $s;		
		}
	}

	responder::send($sites);
});

return $a;

?>