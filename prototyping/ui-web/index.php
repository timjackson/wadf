<?php
require_once('XVar.class.php');
require_once('webdeploy.class.php');

XVar::register('client',XVAR_STANDARD);
XVar::register('system',XVAR_STANDARD);
XVar::register('type',XVAR_STANDARD);
XVar::register('rev',XVAR_STANDARD);
XVar::register('db_deploy',XVAR_STANDARD);

if (!$client) $Errors[] = 'No client code entered';
if (!$system) $Errors[] = 'No system code entered';
if (!$rev) $Errors[] = 'No SVN revision entered';

$VC_DEPLOY_OPTIONS = array(
	WEBDEPLOY_VCREFTYPE_REV => 'Revision',
	WEBDEPLOY_VCREFTYPE_TAG => 'Tag',
	WEBDEPLOY_VCREFTYPE_BRANCH => 'Branch',
	WEBDEPLOY_VCREFTYPE_TRUNK => 'Trunk'
);

$DB_DEPLOY_OPTIONS = array(
	WEBDEPLOY_DBLOC_DEV => 'Dev',
	WEBDEPLOY_DBLOC_STAGING => 'Staging',
	WEBDEPLOY_DBLOC_LIVE => 'Live'
);

if (!isset($Errors)) {
	$rev = trim($rev);
	$Deployment->deploy($client, $system, $rev, $type, $db_deploy);
	print "Deployed";
	die();
}

?>
<html>
<title>Staging System Deployment</title>

<?php if (isset($Errors)) XError::printErrors($Errors) ?>

<form method="post">
<table>
	<tr>
		<th scope="row"><label for="client">Client code:</label></th>
		<td><input name="client" id="client" /></td>
	</tr>
	<tr>
		<th scope="row"><label for="system">System code:</label></th>
		<td><input name="system" id="system" /></td>
	</tr>
	<tr>
		<th scope="row">What to check out</th>
		<td>
			<select name="type" onchange="if (this.value == 'Trunk') { document.getElementById('rev').disabled = true; } else { document.getElementById('rev').disabled = false; }"><?= XArray::createSelect($VC_DEPLOY_OPTIONS) ?></select>
			<input name="rev" id="rev" />
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="db-deploy">Database to use:</label></th>
		<td><select name="db_deploy" id="db-deploy"><?= XArray::createSelect($DB_DEPLOY_OPTIONS, $db_deploy) ?></select></td>
	</tr>
	<tr><td></td><td><input type="submit" value="Deploy" /></td></tr>
</table>
</html>