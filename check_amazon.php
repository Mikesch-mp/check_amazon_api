#!/usr/bin/php71
<?php

// Defaults
$region = "de";
$warning = '';
$critical = '';

$allowed_regions = array('ca','com','co.uk','de','fr','co.jp','es','it','nl');


function print_help_message() {
print <<< END
Usage: check_amazon -a ASIN -p PUBLIC_KEY -k PRIVATE_KEY -i PARTNER_ID [-r region] [-w warning] [-c critical]
                -a      Amazon Standard Identification Number
		-p	AWS public key
		-k	AWS private key
		-i 	AWS Partner id 
		-r 	Region to lookup items (ca,com,co.uk,de,fr,co.jp)
                -w      (optional) warning threshold if price drops below
                -c      (optional) critical threshold if price drops below

Usage: check_amazon -h

END;
}
function aws_signed_request($region,$params,$public_key,$private_key,$associate_tag=NULL,$version='2011-08-01')
{
    /*
    Copyright (c) 2009-2012 Ulrich Mierendorff

    Permission is hereby granted, free of charge, to any person obtaining a
    copy of this software and associated documentation files (the "Software"),
    to deal in the Software without restriction, including without limitation
    the rights to use, copy, modify, merge, publish, distribute, sublicense,
    and/or sell copies of the Software, and to permit persons to whom the
    Software is furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in
    all copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
    THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
    FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
    DEALINGS IN THE SOFTWARE.
    */
    
    /*
    Parameters:
        $region - the Amazon(r) region (ca,com,co.uk,de,fr,co.jp)
        $params - an array of parameters, eg. array("Operation"=>"ItemLookup",
                        "ItemId"=>"B000X9FLKM", "ResponseGroup"=>"Small")
        $public_key - your "Access Key ID"
        $private_key - your "Secret Access Key"
        $version (optional)
    */
    
    // some paramters
    $method = 'GET';
    $host = 'ecs.amazonaws.'.$region;
    $uri = '/onca/xml';
    
    // additional parameters
    $params['Service'] = 'AWSECommerceService';
    $params['AWSAccessKeyId'] = $public_key;
    // GMT timestamp
    $params['Timestamp'] = gmdate('Y-m-d\TH:i:s\Z');
    // API version
    $params['Version'] = $version;
    if ($associate_tag !== NULL) {
        $params['AssociateTag'] = $associate_tag;
    }
    
    // sort the parameters
    ksort($params);
    
    // create the canonicalized query
    $canonicalized_query = array();
    foreach ($params as $param=>$value)
    {
        $param = str_replace('%7E', '~', rawurlencode($param));
        $value = str_replace('%7E', '~', rawurlencode($value));
        $canonicalized_query[] = $param.'='.$value;
    }
    $canonicalized_query = implode('&', $canonicalized_query);
    
    // create the string to sign
    $string_to_sign = $method."\n".$host."\n".$uri."\n".$canonicalized_query;
    
    // calculate HMAC with SHA256 and base64-encoding
    $signature = base64_encode(hash_hmac('sha256', $string_to_sign, $private_key, TRUE));
    
    // encode the signature for the request
    $signature = str_replace('%7E', '~', rawurlencode($signature));
    
    // create request
    $request = 'https://'.$host.$uri.'?'.$canonicalized_query.'&Signature='.$signature;
    
    return $request;
}

$opts = getopt("a:p:k:i:r:w:c:h");

// Handle command line arguments

foreach (array_keys($opts) as $opt) switch ($opt) {
  case 'a':
    $assin = $opts['a'];
    break;

  case 'p':
    $public_key = $opts['p'];
    break;

  case 'k':
    $private_key = $opts['k'];
    break;

  case 'i':
    $partner_id = $opts['i'];
    break;

  case 'r':
    if (in_array($opts['r'], $allowed_regions)) {
       $region = $opts['r'];
    } else {
       print "Unkown region " . $opts['r'] . "!\n";
       print_help_message();
       exit (3);
    }
    break;

  case 'w':
    $warning = $opts['w'];
    break;

  case 'c':
    $critical = $opts['c'];
    break;

  case 'h':
    print_help_message();
    exit(0);
    break;
}

if (!array_key_exists('a', $opts)) {
  print "No ASIN given!\n";
  print_help_message();
  exit (3);
  }

if (!array_key_exists('p', $opts)) {
  print "No public key given!\n";
  print_help_message();
  exit (3);
  }

if (!array_key_exists('k', $opts)) {
  print "No private key given!\n";
  print_help_message();
  exit (3);
  }

if (!array_key_exists('i', $opts)) {
  print "No partner id given!\n";
  print_help_message();
  exit (3);
  }

// Here we go

$my_request = aws_signed_request($region,
	array(
	   "Operation"	        => "ItemLookup",
	   "ItemId"   	        => $assin,
	   "ResponseGroup"	=> "Medium,Offers"),
	$public_key,$private_key,$partner_id);

// do request
$xmlItemResult = @simplexml_load_file($my_request);

if ($xmlItemResult === false)
   {
	echo "Could not fetch XML data from Amazon!";
	exit(3);
   }
// link to item
$link = $xmlItemResult->Items->Item->DetailPageURL;
// Price of item
$price = ($xmlItemResult->Items->Item->Offers->Offer->OfferListing->Price->Amount / 100 );
$currency = $xmlItemResult->Items->Item->Offers->Offer->OfferListing->Price->CurrencyCode;
$title = $xmlItemResult->Items->Item->ItemAttributes->Title;
$perfdata = '| price='. $price .';'. $warning .';'. $critical .';;';

if (($price <= $critical) && ($critical >= 0))
   {
	$output = '!BUY NOW! - Price for <a href="'. $link .'" target="_blank">'. $title .'</a> is '. $price .' '. $currency .'. '. $perfdata;
	$exitcode = 2;
   }
elseif (($price <= $warning) && ($warning >= 0))
   {
        $output = 'Buy - Price for <a href="'. $link .'" target="_blank">'. $title .'</a> is '. $price .' '. $currency .'. '. $perfdata;
        $exitcode = 1;
   }
else
   {
	$output = 'Price for <a href="'. $link .'" target="_blank">'. $title .'</a> is '. $price .' '. $currency .'. '. $perfdata;
	$exitcode = 0;
   }
echo $output;
exit ($exitcode);
?>
