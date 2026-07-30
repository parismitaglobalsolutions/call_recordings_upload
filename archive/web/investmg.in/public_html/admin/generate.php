<?php

$passwords = [
  
];

echo "<pre>";

foreach ($passwords as $user => $plainPassword) {
    $hash = password_hash($plainPassword, PASSWORD_BCRYPT);
    echo "Username: {$user}\n";
    echo "Plain: {$plainPassword}\n";
    echo "Hash: {$hash}\n";
    echo "---------------------------------------------\n";
}

echo "</pre>";