#!/usr/bin/env php
<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

require_once 'Console/CommandLine.php';
require_once 'silverorange/Trac2Github/Converter.php';

$converter = new silverorange\Trac2Github\Converter();
$converter();
exit();
