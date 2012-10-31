<?php
/********************************************************************************
*                                                                               *
*   Copyright 2012 Nicolas CARPi (nicolas.carpi@gmail.com)                      *
*   http://www.elabftw.net/                                                     *
*                                                                               *
********************************************************************************/

/********************************************************************************
*  This file is part of eLabFTW.                                                *
*                                                                               *
*    eLabFTW is free software: you can redistribute it and/or modify            *
*    it under the terms of the GNU Affero General Public License as             *
*    published by the Free Software Foundation, either version 3 of             *
*    the License, or (at your option) any later version.                        *
*                                                                               *
*    eLabFTW is distributed in the hope that it will be useful,                 *
*    but WITHOUT ANY WARRANTY; without even the implied                         *
*    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR                    *
*    PURPOSE.  See the GNU Affero General Public License for more details.      *
*                                                                               *
*    You should have received a copy of the GNU Affero General Public           *
*    License along with eLabFTW.  If not, see <http://www.gnu.org/licenses/>.   *
*                                                                               *
********************************************************************************/
/* auth + connect + functions*/
$ini_arr = parse_ini_file('admin/config.ini');
session_start();
require_once('inc/functions.php');
// SQL CONNECT
try
{
    $pdo_options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
    $bdd = new PDO('mysql:host='.$ini_arr['db_host'].';dbname='.$ini_arr['db_name'], $ini_arr['db_user'], $ini_arr['db_password'], $pdo_options);
}
catch(Exception $e)
{
    die('Error : '.$e->getMessage());
}
// END SQL CONNECT

// AUTH
// if user is not auth
if (!isset($_SESSION['auth'])){
    // If user has a cookie; check cookie is valid
    if (isset($_COOKIE['token']) && (strlen($_COOKIE['token']) == 32)){
            $token = filter_var($_COOKIE['token'], FILTER_SANITIZE_STRING);
            // Get token from SQL
            $sql = "SELECT * FROM users WHERE token = :token";
            $result = $bdd->prepare($sql);
            $result->execute(array(
                'token' => $token
            ));
            $data = $result->fetch();
            $numrows = $result->rowCount();
            // Check cookie path vs. real install path
            if (($numrows == 1) && (dirname(__FILE__) == $_COOKIE['path'])) { // token is valid
                // Store userid in $_SESSION
                session_regenerate_id();
                $_SESSION['auth'] = 1;
                // fix for cookies problem
                $_SESSION['path'] = $ini_arr['path'];
                $_SESSION['userid'] = $data['userid'];
                // Used in the menu
                $_SESSION['username'] = $data['username'];
                $_SESSION['is_admin'] = $data['is_admin'];
                // PREFS
                $_SESSION['prefs'] = array('theme' => $data['theme'], 
                    'display' => $data['display'], 
                    'order' => $data['order_by'], 
                    'sort' => $data['sort_by'], 
                    'limit' => $data['limit_nb'], 
                    'shortcuts' => array('create' => $data['sc_create'], 'edit' => $data['sc_edit'], 'submit' => $data['sc_submit'], 'todo' => $data['sc_todo']));
                session_write_close();
                }else{ // no token found in database
                    $msg_arr = array();
                    $msg_arr[] = 'You are not logged in !';
                    $_SESSION['errors'] = $msg_arr;
                    header("location: login.php");
                }
    }else{ // no cookie
        $msg_arr = array();
        $msg_arr[] = 'You are not logged in !';
        $_SESSION['errors'] = $msg_arr;
        header("location: login.php");
    }
} else { // user is auth but we check for path
    if (isset($_COOKIE['path'])) {
        // two dirname because we are in /inc
        if (dirname(dirname(__FILE__)) != $_COOKIE['path']) {
            die('bad path'.dirname(dirname(__FILE__)));
        }
    } else { // auth = 1 but no cookies => we kill session
        session_destroy();
        header('Location: login.php');
    }
}
?>
