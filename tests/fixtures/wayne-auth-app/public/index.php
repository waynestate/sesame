<?php

// A legacy page, written the way Wayne Auth apps are: an ini file, a session,
// the old class, and an if/else around authenticateSSO(). The only line that
// changed for SESAME is marked; everything else is what the app already had.

chdir(dirname(__FILE__) . '/..');

$config = parse_ini_file('config/config.ini', true);

session_start();

// Was: require_once('lib/WayneAuth/WayneAuth.php');
require_once('config/sesame.php');

if (array_key_exists('auth', $config) and array_key_exists('enabled', $config['auth'])) {
    $wayne_auth = new WayneAuth($config['wayneauth']);
    if ($wayne_auth->authenticateSSO() == true) {
        $accessid = $wayne_auth->getAccessID();

        $users = explode(',', $config['auth']['authorized_ids']);
        if (!in_array($accessid, $users)) {
            echo 'User authenticated but not authorized';
            exit;
        }
    } else {
        header('Location: ' . $config['auth']['login_url'] . $config['general']['url_root']);
        exit;
    }
} else {
    echo 'No auth variable';
    exit;
}

echo 'Signed in as ' . htmlspecialchars($accessid, ENT_QUOTES);
