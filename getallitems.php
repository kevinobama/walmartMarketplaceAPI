<?php
require('lib/WalmartAccount.php');
require('lib/WalmartFeatures.php');

$walmartAccount = new WalmartAccount();
 
//$walmartAccount = WalmartAccount::getOne("wall26");
$listings = WalmartFeatures::getBatchItems($walmartAccount);

error_log('-------------------');
print_r($listings);