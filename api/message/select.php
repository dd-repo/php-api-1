<?php

if( !defined('PROPER_START') )
{
	header("HTTP/1.0 403 Forbidden");
	exit;
}

$a = new action();
$a->addAlias(array('list', 'view', 'search'));
$a->setDescription("Searches for a messages");
$a->addGrant(array('ACCESS', 'MESSAGE_SELECT'));
$a->setReturn(array(array(
	'title'=>'the title of the message', 
	'content'=>'the message content',
	'user'=>'the message user',
	'date'=>'the message date'
	)));
$a->addParam(array(
	'name'=>array('id', 'message', 'message_id'),
	'description'=>'The id of the message',
	'optional'=>true,
	'minlength'=>1,
	'maxlength'=>11,
	'match'=>request::NUMBER
	));
$a->addParam(array(
	'name'=>array('parent', 'parent_id'),
	'description'=>'The id of parent of the message',
	'optional'=>true,
	'minlength'=>1,
	'maxlength'=>11,
	'match'=>request::NUMBER
	));
$a->addParam(array(
	'name'=>array('type', 'message_type'),
	'description'=>'The type of the message',
	'optional'=>true,
	'minlength'=>1,
	'maxlength'=>1,
	'match'=>request::NUMBER
	));
$a->addParam(array(
	'name'=>array('limit'),
	'description'=>'Limit the result',
	'optional'=>true,
	'minlength'=>1,
	'maxlength'=>11,
	'match'=>request::NUMBER
	));
$a->addParam(array(
	'name'=>array('status'),
	'description'=>'Search only for status.',
	'optional'=>true,
	'minlength'=>1,
	'maxlength'=>2,
	'match'=>request::NUMBER
	));
$a->addParam(array(
	'name'=>array('topic'),
	'description'=>'Search only topics.',
	'optional'=>true,
	'minlength'=>1,
	'maxlength'=>5,
	'match'=>"(1|0|yes|no|true|false)"
	));
$a->addParam(array(
	'name'=>array('user', 'user_name', 'username', 'login', 'user_id', 'uid'),
	'description'=>'The name or id of the target user.',
	'optional'=>true,
	'minlength'=>0,
	'maxlength'=>50,
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
	$id = $a->getParam('id');
	$parent = $a->getParam('parent');
	$type = $a->getParam('type');
	$status = $a->getParam('status');
	$topic = $a->getParam('topic');
	$limit = $a->getParam('limit');
	$user = $a->getParam('user');
		
	if( $topic == '1' || $topic == 'yes' || $topic == 'true' || $topic === true || $topic === 1 )
		$topic = true;
	else
		$topic = false;
	
	if( $limit === null )
		$limit = 100;
	
	// =================================
	// PREPARE WHERE CLAUSE
	// =================================
	$where = '';
	if( $id !== null )
		$where .= " AND message_id = '".security::escape($id)."'";
	if( $user !== null )
	{
		if( is_numeric($user) )
			$where .= " AND u.user_id = " . $user;
		else
			$where .= " AND u.user_name = '".security::escape($user)."'";
	}
	if( $id !== null )
		$where .= " AND message_id = '".security::escape($id)."'";
	if( $status !== null )
		$where .= " AND message_status = {$status}";
	if( $topic === true )
		$where .= " AND message_parent = 1";
	if( $parent !== null )
		$where .= " AND message_parent = {$parent}";
		
	// =================================
	// SELECT RECORDS
	// =================================
	$sql = "SELECT m.message_title, m.message_content, m.message_date, m.message_parent, m.message_id, m.message_type, m.message_status, m.message_ip, u.user_name, u.user_id, u.user_date, u.user_status
	FROM messages m LEFT JOIN users u ON(u.user_id = m.message_user)
	WHERE m.message_status != 0 {$where} ORDER BY m.message_id DESC LIMIT 0,{$limit}";
	$result = $GLOBALS['db']->query($sql, mysql::ANY_ROW);

	// =================================
	// FORMAT RESULT
	// =================================
	$messages = array();
	foreach( $result as $r )
	{
		$m['id'] = $r['message_id'];
		$m['title'] = $r['message_title'];
		$m['content'] = $r['message_content'];
		$m['parent'] = $r['message_parent'];
		$m['date'] = $r['message_date'];
		$m['type'] = $r['message_type'];
		$m['status'] = $r['message_status'];
		$m['ip'] = $r['message_ip'];
		$m['user'] = array('id'=>$r['user_id'], 'name'=>$r['user_name'], 'date'=>$r['user_date'], 'status'=>$r['user_status']);
		
		$messages[] = $m;
	}

	responder::send($messages);
});

return $a;

?>