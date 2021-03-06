<?php

if( !defined('PROPER_START') )
{
	header("HTTP/1.0 403 Forbidden");
	exit;
}

// WARNING : THIS PAGE ONLY PROVIDES 2 FUNCTIONS TO CHECK/SYNC
// THE USER QUOTA. IT SHOULD NOT BE CALLED DIRECTLY.

security::requireGrants(array('QUOTA_USER_INTERNAL'));

function checkQuota($type, $user)
{
	if( is_numeric($user) )
		$where = "u.user_id=".$user;
	else
		$where = "u.user_name = '".security::escape($user)."'";
	
	$sql = "SELECT uq.quota_max, uq.quota_used
			FROM quotas q 
			LEFT JOIN user_quota uq ON(q.quota_id = uq.quota_id)
			LEFT JOIN users u ON(u.user_id = uq.user_id)
			WHERE q.quota_name='".security::escape($type)."' 
			AND {$where}";
	$result = $GLOBALS['db']->query($sql);
	
	if( $result == null || $result['quota_max'] == null || $result['quota_used'] >= $result['quota_max'] )
		throw new ApiException("Unsufficient quota", 412, "Quota limit reached or not set : {$result['quota_used']}/{$result['quota_max']}");
}

function syncQuota($type, $user)
{
	if( is_numeric($user) )
		$where = "u.user_id=".$user;
	else
		$where = "u.user_name = '".security::escape($user)."'";

	$count = "quota_used";
	switch($type)
	{
		case 'SITES':
			$sql = "SELECT user_ldap FROM users u WHERE {$where}";
			$userdata = $GLOBALS['db']->query($sql);
			if( $userdata == null || $userdata['user_ldap'] == null )
				throw new ApiException("Unknown user", 412, "Unknown user : {$user}");
			$user_dn = $GLOBALS['ldap']->getDNfromUID($userdata['user_ldap']);
			$result = $GLOBALS['ldap']->search('dc=olympe,dc=in,dc=dns', ldap::buildFilter(ldap::SUBDOMAIN, "(owner={$user_dn})"));
			$count = count($result);
			break;
		case 'DOMAINS':
			$sql = "SELECT user_ldap FROM users u WHERE {$where}";
			$userdata = $GLOBALS['db']->query($sql);
			if( $userdata == null || $userdata['user_ldap'] == null )
				throw new ApiException("Unknown user", 412, "Unknown user : {$user}");
			$user_dn = $GLOBALS['ldap']->getDNfromUID($userdata['user_ldap']);
			$result = $GLOBALS['ldap']->search($GLOBALS['CONFIG']['LDAP_BASE'], ldap::buildFilter(ldap::DOMAIN, "(owner={$user_dn})"));
			$count = count($result);
			break;
		case 'DATABASES':
			$sql = "SELECT COUNT(*) as c
					FROM `databases` d
					LEFT JOIN users u ON(u.user_id = d.database_user)
					WHERE {$where}";
			$result = $GLOBALS['db']->query($sql);
			if( $result == null || $result['c'] == null )
				throw new ApiException("Unknown user", 412, "Unknown user : {$user}");
			$count = $result['c'];
			break;
		case 'BYTES':			
			$sql = "SELECT user_ldap, user_id, user_name FROM users u WHERE {$where}";
			$userdata = $GLOBALS['db']->query($sql);
			if( $userdata == null || $userdata['user_ldap'] == null )
				throw new ApiException("Unknown user", 412, "Unknown user : {$user}");
				
			$sql = "SELECT quota_max FROM user_quota WHERE quota_id = 13 AND user_id = {$userdata['user_id']}";
			$quotadata = $GLOBALS['db']->query($sql);
			
			$user_dn = $GLOBALS['ldap']->getDNfromUID($userdata['user_ldap']);
			$usage = 0;
			$usage = $GLOBALS['system']->getquota($userdata['user_ldap'], 'group', $quotadata['quota_max']);
			$usage = round($usage/1024);

			$sql = "SELECT storage_size, storage_id FROM storages WHERE storage_path = '/dns/in/olympe/Users/{$userdata['user_name']}'";
			$store = $GLOBALS['db']->query($sql);
			if( $store['storage_id'] )
				$sql = "UPDATE storages SET storage_size = {$usage} WHERE storage_id = {$store['storage_id']}";
			else
				$sql = "INSERT INTO storages (storage_path, storage_size) VALUES ('/dns/in/olympe/Users/{$userdata['user_name']}', {$usage})";
			$GLOBALS['db']->query($sql, mysql::NO_ROW);
				
			$sites = $GLOBALS['ldap']->search(ldap::buildDN(ldap::DOMAIN, $GLOBALS['CONFIG']['DOMAIN']), ldap::buildFilter(ldap::SUBDOMAIN, "(owner={$user_dn})"));
			foreach( $sites as $s )
			{
				$u = 0;
				$u = $GLOBALS['system']->getquota($s['uidNumber'], 'user', $quotadata['quota_max']);
				$u = round($u/1024);
				
				$sql = "SELECT storage_size, storage_id FROM storages WHERE storage_path = '{$s['homeDirectory']}'";
				$store = $GLOBALS['db']->query($sql);
				if( $store['storage_id'] )
					$sql = "UPDATE storages SET storage_size = {$u} WHERE storage_id = {$store['storage_id']}";
				else
					$sql = "INSERT INTO storages (storage_path, storage_size) VALUES ('{$s['homeDirectory']}', {$u})";
				$GLOBALS['db']->query($sql, mysql::NO_ROW);
				
				$usage = $usage+$u;
			}
			
			$users = $GLOBALS['ldap']->search($GLOBALS['CONFIG']['LDAP_BASE'], ldap::buildFilter(ldap::USER, "(owner={$user_dn})"));
			foreach( $users as $user )
			{
				$u = 0;
				$u = $GLOBALS['system']->getquota($user['uidNumber'], 'user', $quotadata['quota_max']);
				$u = round($u/1024);
				
				$sql = "SELECT storage_size, storage_id FROM storages WHERE storage_path = '{$user['homeDirectory']}'";
				$store = $GLOBALS['db']->query($sql);
				if( $store['storage_id'] )
					$sql = "UPDATE storages SET storage_size = {$u} WHERE storage_id = {$store['storage_id']}";
				else
					$sql = "INSERT INTO storages (storage_path, storage_size) VALUES ('{$user['homeDirectory']}', {$u})";
				$GLOBALS['db']->query($sql, mysql::NO_ROW);
				
				$usage = $usage+$u;
			}
			
			$sql = "SELECT * FROM `databases` WHERE database_user = {$userdata['user_id']}";
			$databases = $GLOBALS['db']->query($sql, mysql::ANY_ROW);
			foreach( $databases as $d )
			{
				$u = 0;
				$u = $GLOBALS['system']->getservicesize($d['database_name'], $d['database_type'], $d['database_server']);
				$u = round($u/1024);
				if( $d['database_type'] == 'pgsql' )
					$u = round($u/1024);

				$sql = "SELECT storage_size, storage_id FROM storages WHERE storage_path = '/databases/{$d['database_name']}'";
				$store = $GLOBALS['db']->query($sql);
				if( $store['storage_id'] )
					$sql = "UPDATE storages SET storage_size = {$u} WHERE storage_id = {$store['storage_id']}";
				else
					$sql = "INSERT INTO storages (storage_path, storage_size) VALUES ('/databases/{$d['database_name']}', {$u})";
				$GLOBALS['db']->query($sql, mysql::NO_ROW);
				
				$usage = $usage+$u;
			}
			
			$count = $usage;
			break;
		default:
			throw new ApiException("Undefined quota type", 500, "Not preconfigured for quota type : {$type}");
	}
	
	if( $count !== null && $count !== false )
	{
		$sql = "UPDATE IGNORE user_quota 
			SET quota_used={$count}
			WHERE quota_id IN (SELECT q.quota_id FROM quotas q WHERE q.quota_name='".security::escape($type)."')
			AND user_id IN (SELECT u.user_id FROM users u WHERE {$where})";
			
		$GLOBALS['db']->query($sql, mysql::NO_ROW);
	}
	
}

// ========================= DECLARE ACTION

$a = new action();
$a->addAlias(array('internal'));
$a->setDescription("Include utility functions for the quota");
$a->addGrant(array('QUOTA_USER_INTERNAL'));

$a->setExecute(function() use ($a)
{
	$a->checkAuth();
});

return $a;

?>
