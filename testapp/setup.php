#!/usr/bin/php
<?php

$db_was_reset = getenv('DEPLOY_INITDB');

if ($db_was_reset) {
	print "Resetting database...\n";
}

// do non-database stuff
