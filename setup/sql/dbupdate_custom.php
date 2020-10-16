<#1>
<?php
//changes for celtic/lti

if($ilDB->tableExists('lti2_consumer'))
{
	if(!$ilDB->tableColumnExists('lti2_consumer', 'signature_method') )
	{
		$ilDB->addTableColumn('lti2_consumer', 'signature_method', array(
			"type" => "text",
			"notnull" => true,
			"length" => 15,
			"default" => 'HMAC-SHA1'
		));
	}
}

if($ilDB->tableExists('lti2_context'))
{
	if(!$ilDB->tableColumnExists('lti2_context', 'title') )
	{
		$ilDB->addTableColumn('lti2_context', 'title', array(
			"type" => "text",
			"notnull" => false,
			"length" => 255,
			"default" => null
		));
	}
}

if($ilDB->tableExists('lti2_context'))
{
	if(!$ilDB->tableColumnExists('lti2_context', 'type') )
	{
		$ilDB->addTableColumn('lti2_context', 'type', array(
			"type" => "text",
			"notnull" => false,
			"length" => 50,
			"default" => null
		));
	}
}

if($ilDB->tableExists('lti2_resource_link'))
{
	if(!$ilDB->tableColumnExists('lti2_resource_link', 'title') )
	{
		$ilDB->addTableColumn('lti2_resource_link', 'title', array(
			"type" => "text",
			"notnull" => false,
			"length" => 255,
			"default" => null
		));
	}
}

//note: field user_result_pk in table lti2_user_result is not used in ILIAS; use user_pk as in implementation of IMSGLOBAL

if($ilDB->tableExists('lti2_nonce'))
{
	if($ilDB->tableColumnExists('lti2_nonce', 'value') )
	{
		$ilDB->modifyTableColumn('lti2_nonce', 'value', array(
			'type' => 'text',
			'length' => 50,
			'notnull' => true
		));
	}
}
?>