<?php
// Prints the current 6-digit two-step code for the DEMO admin and finance accounts (secret JBSWY3DPEHPK3PXP). Demo use only.
$secret = $argv[1] ?? 'JBSWY3DPEHPK3PXP';
$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = '';
foreach (str_split($secret) as $c) { $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT); }
$key = ''; foreach (str_split($bits, 8) as $b) { if (strlen($b) === 8) { $key .= chr(bindec($b)); } }
$step = intdiv(time(), 30);
$h = hash_hmac('sha1', pack('J', $step), $key, true);
$o = ord($h[19]) & 15;
$code = (unpack('N', substr($h, $o, 4))[1] & 0x7fffffff) % 1000000;
printf("%06d   (valid for about %d more seconds)\n", $code, 30 - (time() % 30));
