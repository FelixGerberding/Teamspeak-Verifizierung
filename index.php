<?php

    $error = array();
    session_start();
    if (file_exists('config/config.php')) {
        require_once('config/config.php');
    } else {
        session_unset();
    }


    function ts3connect() {
        require_once('libraries/TeamSpeak3/TeamSpeak3.php');
        if (strlen(QUERYDISPLAYNAME) < 3) {
            $extension = "";
        } else {
            $extension = '&nickname=' . urlencode(QUERYDISPLAYNAME) . "-" . rand(1,999);
        }
        try {
            $ts3 = TeamSpeak3::factory(
                'serverquery://'.QUERYUSER.':'.QUERYPASS.'@'.IP.':'.QUERYPORT.'?server_port='.SERVERPORT.$extension
            );
        } catch (TeamSpeak3_Exception $e) {
            return $e;
        }
        return $ts3;
    }

    function getClientIp() {
        if (!empty($_SERVER['HTTP_CLIENT_IP']))
            return $_SERVER['HTTP_CLIENT_IP'];
        else if(!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if(!empty($_SERVER['HTTP_X_FORWARDED']))
            return $_SERVER['HTTP_X_FORWARDED'];
        else if(!empty($_SERVER['HTTP_FORWARDED_FOR']))
            return $_SERVER['HTTP_FORWARDED_FOR'];
        else if(!empty($_SERVER['HTTP_FORWARDED']))
            return $_SERVER['HTTP_FORWARDED'];
        else if(!empty($_SERVER['REMOTE_ADDR']))
            return $_SERVER['REMOTE_ADDR'];
        else
            return false;
    }

    function randStr($length = 5) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }


    if (empty($_SESSION['step']['client'])) {
        if (!file_exists('config/config.php')) {
            $_SESSION['step']['client'] = '';
            $error[] = array('danger', 'Keine Config gefunden. Bitte verwende das <b><a href="admin.php">Interface</a></b> um die Verifizierung einzurichten!');
        } else {
            $_SESSION['step']['client'] = 'client_selection';
        }
    }


    if ($_SESSION['step']['client'] == 'client_selection') {
        if (empty($ts3)) $ts3 = ts3connect();
        if (is_string($ts3)) {
            $error[] = array('danger', 'Konnte sich nicht mit dem Server verbinden. Fehlermeldung: '.$ts3.' <br>Wenn du Hilfe benötigst, wende dich an <a href="mailto:felix@opossumts.net">DrOpossum</a>.');
        } else {
            $detected_clients = $ts3->clientList(array('client_type' => '0', 'connection_client_ip' => getClientIp()));
            if (!empty($_POST['uid'])) {
                if (strlen($_POST['uid']) != 28 || substr($_POST['uid'], -1, 1) != '=') {
                    $error[] = array('danger', 'Ungültige UUID!');
                } else {
                    $skip = false;
                    try {
                        $client = $ts3->clientGetByUid($_POST['uid']);
                    } catch (TeamSpeak3_Exception $e) {
                        $skip = true;
                        if ($e->getMessage() == 'invalid clientID') {
                            $error[] = array('danger', 'Es ist kein Nutzer mit dieser UUID online!');
                        } else {
                            $error[] = array('danger', 'Fehler: ('.$e.')');
                        }
                    }
                    if (!$skip) {
                        $disallow = false;
                        foreach (json_decode(GROUPSDISALLOW,1) as $grp) {
                            if (in_array($grp, explode(',', $client->client_servergroups))) {
                                $disallow = true;
                                break;
                            }
                        }
                        if (!$disallow) {
                            $_SESSION['dbid'] = $client->client_database_id;
                            $_SESSION['clid'] = $client->clid;
                            $_SESSION['step']['client'] = 'verify';
                        } else{
                            $error[] = array('danger', 'Deine Servergruppe kann dieses Tool nicht verwenden.');
                        }
                    }
                }
            } else if (!empty($_POST['clid'])) {
                $found = false;
                foreach ($detected_clients as $client) {
                    if ($client->clid == $_POST['clid']) {
                        $disallow = false;
                        foreach (json_decode(GROUPSDISALLOW,1) as $grp) {
                            if (in_array($grp, explode(',', $client->client_servergroups))) {
                                $disallow = true;
                                break;
                            }
                        }
                        if (!$disallow) {
                            $_SESSION['dbid'] = $client->client_database_id;
                            $_SESSION['clid'] = $client->clid;
                            $_SESSION['step']['client'] = 'verify';
                            $found = true;
                        } else{
                            $error[] = array('danger', 'Deine Servergruppe kann dieses Tool nicht verwenden.');
                            $found = true;
                        }
                        break;
                    }
                }
                if (!$found) {
                    $error[] = array('danger', 'Der ausgewählte User konnte nicht gefunden werden.');
                }
            }
        }
    }




    if ($_SESSION['step']['client'] and !empty($_SESSION['verify_code']) and !empty($_POST['code'])) {
        if ($_SESSION['verify_code'] === $_POST['code']) {
            unset($_SESSION['verify_code']);
            if (RULESACTIVATE) {
                $_SESSION['step']['client'] = 'rules';
            } else {
                $_SESSION['step']['client'] = 'assigner';
            }
        } else {
            $invalidCode = true;
            if ($_SESSION['spam_protection'] < 3) {
                $error[] = array('danger', 'Dieser Code ist ungültig, dir wurde ein neuer geschickt.');
            } else {
                $error[] = array('danger', 'Dieser Code ist ungültig.');
            }
        }
    }




    if ($_SESSION['step']['client'] == 'verify') {
        if (empty($_SESSION['spam_protection'])) {
            $_SESSION['spam_protection'] = 0;
        }
        if (!empty($_GET['changeUser'])) {
            if ($_SESSION['spam_protection'] == 3) {
                $_SESSION['spam_protection'] = 2;
            }
            unset($_SESSION['verify_code']);
            unset($_SESSION['clid']);
            unset($_SESSION['dbid']);
            $_SESSION['step']['client'] = 'client_selection';
            header("Refresh:0");
            die();
        }
        if (empty($ts3)) $ts3 = ts3connect();
        if (is_string($ts3)) {
            $error[] = array('danger', 'Query konnte sich nicht zum Server verbinden! '.$ts3);
        } else {
            if (
                empty($_SESSION['verify_code'])
                || !empty($_GET['request_new_code'])
                || !empty($invalidCode)
            ) {
                if ($_SESSION['spam_protection'] < 3) {
                    $_SESSION['verify_code'] = randStr(8);
                    $skip = false;
                    try {
                        $ts3->clientGetById($_SESSION['clid'])->poke('Verifizierungscode: '.$_SESSION['verify_code']);
                        $_SESSION['spam_protection']++;
                    } catch (TeamSpeak3_Exception $e) {
                        $skip = true;
                        if ($e->getMessage() == 'invalid clientID') {
                            try {
                                $ts3->clientGetByDbid($_SESSION['dbid'])->poke('Verifizierungscode: '.$_SESSION['verify_code']);
                                $_SESSION['spam_protection']++;
                                $error[] = array('info', 'Es wurde kein Client mit dieser Client-ID gefunden - Nachricht wurde an die UUID geschickt.');
                            } catch (TeamSpeak3_Exception $e) {
                                $error[] = array('danger', 'Kein Client gefunden.');
                            }
                        } else {
                            $error[] = array('danger', 'Fehler: ('.$e.')');
                        }
                    }
                } else {
                    $error[] = array('danger', 'Spam-Schutz - Bitte warte 15 Minuten mit dem versenden weiterer Codes!');
                }
            }
        }
    }




    if ($_SESSION['step']['client'] == 'rules') {
        if (!empty($_GET['ablehnen'])) {
            session_unset();
            header("Refresh:0");
            die();
        } else if (!empty($_GET['akzeptiert'])) {
            $continue = true;
            if (empty($ts3)) $ts3 = ts3connect();
            if (is_string($ts3)) {
                $error[] = array('danger', 'Konnte sich nicht mit dem Server verbinden! Fehlermeldung: '.$ts3);
            } else {
                if (RULESACCEPTGROUP != 0 and is_numeric(RULESACCEPTGROUP)) {
                    try {
                        $notallowed = false;
                        foreach (explode(',', $ts3->clientGetByDbid($_SESSION['dbid'])->client_servergroups) as $grp) {
                            if (in_array($grp,json_decode(GROUPSDISALLOW))) {
                                $notallowed = true;
                                break;
                            }
                        }
                        if (!$notallowed) $ts3->serverGroupClientAdd(RULESACCEPTGROUP, $_SESSION['dbid']);
                    } catch (Teamspeak3_Exception $e) {
                        if ($e->getMessage() != 'duplicate entry') {
                            $error[] = array('danger', 'Fehler beim Hinzufügen der Gruppe! Fehlermeldung: '.$e);
                            $continue = false;
                        }
                    }
                }
                if ($continue) $_SESSION['step']['client'] = 'assigner';
            }
        }
    }

    if ($_SESSION['step']['client'] == 'assigner') {
        $icons = array();
        if (file_exists('config/icons.json')) $icons = json_decode(file_get_contents('config/icons.json'),1);
        if (empty($ts3)) $ts3 = ts3connect();
        if (is_string($ts3)) {
            $error[] = array('danger', 'Konnte sich nicht mit dem Server verbinden! Fehlermeldung: '.$ts3);
        } else {
            try {
                $groups = $ts3->servergroupList(array('type' => 1));
                $cgroups = explode(',',$ts3->clientGetByDbid($_SESSION['dbid'])->client_servergroups);
            } catch (Teamspeak3_Exception $e) {
                $error[] = array('danger', 'Konnte Servergruppen nicht abfragen. Fehlermeldung: '. $e->getMessage());
            }
        }
    }

?>
<html>
    <head>
        <title><?php echo SEITENTITEL ?></title>
        <link href="style/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://www.opossumts.net/style/A.style-2.css,qm=1516980539.pagespeed.cf.aLAxaP_MNG.css" rel="stylesheet">
    </head>
    <body onload="check()">
      <script src="style/js/lazyload.js?v=1" type="text/javascript"></script>
        <div class="row-fluid">
        <?php foreach ($error as $e) { ?>
            <div class="alert alert-<?php echo $e[0]; ?>" role="alert"><?php echo $e[1]; ?></div>
        <?php } ?>
            <?php if ($_SESSION['step']['client'] == 'client_selection') { ?>
                <div style="margin-top:5%;" class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-lg-4 col-sm-offset-4">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">Client zur Verifizierung auswählen</font></b></h5>
                        </div>
                        <div class="panel-body">
                            <form action="index.php" method="POST">
                                <?php if (count($detected_clients) > 1) { ?>
                                    <div class="form-group">
                                        <label for="clid">Wer bist du?</label>
                                        <select class="form-control" name="clid" id="clid">
                                            <?php foreach ($detected_clients as $client) { ?>
                                                <option value="<?php echo $client->clid ?>"><?php echo $client->client_nickname; ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                <?php } else if (count($detected_clients) == 1) { ?>
                                    <?php
                                        $clid = array_keys($detected_clients)[0];
                                    ?>
                                    <div class="form-group">
                                        <label for="dbid">Bist du das?</label>
                                        <input type="text" class="form-control" value="<?php echo $detected_clients[$clid]->client_nickname; ?>" disabled />
                                        <input type="hidden" name="clid" id="clid" value="<?php echo $detected_clients[$clid]->clid; ?>" />
                                    </div>
                                <?php } else { ?>
                                    <div class="form-group">
                                        Das System konnte keinen Nutzer mit deinen IP-Adresse auf dem Server finden.<br/>
                                        Bitte verbinde dich mit dem Teamspeak und lade die Seite neu oder trage deine UUID manuell ein.<br/>
                                    </div>
                                    <div class="form-group">
                                        <label for="uid">UUID</label>
                                        <input type="text" class="form-control" name="uid" id="uid" placeholder="Deine Teamspeak-UUID"/>
                                    </div>
                                <?php } ?>
                                <div class="form-group">
                                    <div class="pull-right">
                                        <button type="submit" class="btn btn-primary">Verifizieren</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="container">
                        <a href="<?php echo IMPRESSUM ?>">Impressum</a> <?php echo hex2bin('3c646976207374796c653d22706f736974696f6e3a6162736f756c74653b2072696768743a313070783b223e3c702069643d22636f70797269676874223e5665726966697a696572756e677373797374656d20766f6e203c6120687265663d2268747470733a2f2f7777772e6f706f7373756d74732e6e6574223e4f706f7373756d54532e6e65743c2f613e203c62723e203c646976207374796c653d22666f6e742d73697a653a3130223e4261736564206f6e2047726f75702d41737369676e6572206279203c6120687265663d2268747470733a2f2f6769746875622e636f6d2f4d756c7469766974346d696e223e4d756c7469766974346d696e3c2f613e3c2f6469763e3c2f703e3c2f6469763e'); ?>
                    </div>
                </div>
            <?php } ?>
            <?php if ($_SESSION['step']['client'] == 'verify') { ?>
                <div style="margin-top:5%;" class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-lg-4 col-sm-offset-4">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">Gebe den Verifizierungscode ein</font></b></h5>
                        </div>
                        <div class="panel-body">
                            <form action="index.php" method="POST">
                                    <div class="form-group">
                                        <label for="uid">Verifizierungscode</label>
                                        <input type="text" class="form-control" name="code" id="code" placeholder="Verifizierungscode"/>
                                    </div>
                                <div class="form-group">
                                    <div class="pull-right">
                                        <button type="submit" class="btn btn-primary">Verifizieren</button>
                                    </div>
                                </div>
                            </form>
                            <div class="btn-group" role="group">
                                <a href="index.php?request_new_code=1" type="button" class="btn btn-default">Code erneut senden</a>
                                <a href="index.php?changeUser=1" type="button" class="btn btn-default">Nutzer wechseln</a>
                            </div>
                        </div>
                    </div>
                    <div class="container">
                        <a href="<?php echo IMPRESSUM ?>">Impressum</a> <?php echo hex2bin('3c646976207374796c653d22706f736974696f6e3a6162736f756c74653b2072696768743a313070783b223e3c702069643d22636f70797269676874223e5665726966697a696572756e677373797374656d20766f6e203c6120687265663d2268747470733a2f2f7777772e6f706f7373756d74732e6e6574223e4f706f7373756d54532e6e65743c2f613e203c62723e203c646976207374796c653d22666f6e742d73697a653a3130223e4261736564206f6e2047726f75702d41737369676e6572206279203c6120687265663d2268747470733a2f2f6769746875622e636f6d2f4d756c7469766974346d696e223e4d756c7469766974346d696e3c2f613e3c2f6469763e3c2f703e3c2f6469763e'); ?>
                    </div>
                </div>
            <?php } ?>
            <?php if ($_SESSION['step']['client'] == 'rules') { ?>
                <div style="margin-top:5%;" class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-lg-4 col-sm-offset-4">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">Regeln</font></b></h5>
                        </div>
                        <div class="panel-body">
                            <ul class="list-group">
                                    <a href="#" class="list-group-item">
                                        <p class="list-group-item-text">Ich akzeptiere die Serverregeln</p>
                                    </a>
                            </ul>
                            <center>
                                <div class="btn-group" role="group">
                                    <a href="index.php?ablehnen=1" type="button" class="btn btn-default">Ablehnen</a>
                                    <a href="index.php?akzeptiert=1" type="button" class="btn btn-primary">Annehmen</a>
                                </div>
                            </center>
                        </div>
                    </div>
                    <div class="container">
                      <a href="<?php echo IMPRESSUM ?>">Impressum</a> <?php echo hex2bin('3c646976207374796c653d22706f736974696f6e3a6162736f756c74653b2072696768743a313070783b223e3c702069643d22636f70797269676874223e5665726966697a696572756e677373797374656d20766f6e203c6120687265663d2268747470733a2f2f7777772e6f706f7373756d74732e6e6574223e4f706f7373756d54532e6e65743c2f613e203c62723e203c646976207374796c653d22666f6e742d73697a653a3130223e4261736564206f6e2047726f75702d41737369676e6572206279203c6120687265663d2268747470733a2f2f6769746875622e636f6d2f4d756c7469766974346d696e223e4d756c7469766974346d696e3c2f613e3c2f6469763e3c2f703e3c2f6469763e'); ?>
                    </div>
                </div>
            <?php } ?>
            <?php if ($_SESSION['step']['client'] == 'assigner') { ?>
                <style>
                    .notInGroup {
                        opacity: 0.1;
                    }
                </style>
                <div style="margin-top:5%;" class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-lg-4 col-sm-offset-4">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">Gruppenzuweisung</font></b><span class="badge pull-right" id="grpdisplay">0 von 0 Gruppen zugewiesen</span></h5>
                        </div>
                        <div class="panel-body">
                            <table style="font-size:13px;" class="table table-sm">
                                <tr>
                                    <th>Icon</th>
                                    <th>Gruppenname (ID)</th>
                                    <th>Icon</th>
                                    <th>Gruppenname (ID)</th>
                                </tr>
                                <?php
                                    $half = floor(count($groups) / 2);
                                    $i = 0;
                                    $active = json_decode(GROUPS);
                                    $grpcount = 0;
                                    foreach ($cgroups as $grp) {
                                        if (in_array($grp, $active)) $grpcount++;
                                    }
                                ?>
                                <?php foreach ($groups as $grp) { ?>
                                    <?php if (array_key_exists($grp->sgid, $icons) and in_array($grp->sgid, $active)) { ?>
                                    <?php if ($i % 2 == 0) echo '<tr>'; ?>
                                        <td onclick="toggleGroup(<?php echo $grp->sgid; ?>)"><img <?php if (!in_array($grp->sgid, $cgroups)) echo 'class="notInGroup"'; ?> id="img_<?php echo $grp->sgid ?>" height="16" width="16" src="<?php echo $icons[$grp->sgid] ?>"></img></td>
                                        <td onclick="toggleGroup(<?php echo $grp->sgid; ?>)"><button class="btn btn-primary btn-xs"><?php echo $grp->name ?></button></td>
                                    <?php if ($i % 2 == 1) echo '</tr>'; ?>
                                <?php $i++;}} ?>
                            </table>
                        </div>
                    </div>
                    <div class="container">
                        <a href="<?php echo IMPRESSUM ?>">Impressum</a> <?php echo hex2bin('3c646976207374796c653d22706f736974696f6e3a6162736f756c74653b2072696768743a313070783b223e3c702069643d22636f70797269676874223e5665726966697a696572756e677373797374656d20766f6e203c6120687265663d2268747470733a2f2f7777772e6f706f7373756d74732e6e6574223e4f706f7373756d54532e6e65743c2f613e203c62723e203c646976207374796c653d22666f6e742d73697a653a3130223e4261736564206f6e2047726f75702d41737369676e6572206279203c6120687265663d2268747470733a2f2f6769746875622e636f6d2f4d756c7469766974346d696e223e4d756c7469766974346d696e3c2f613e3c2f6469763e3c2f703e3c2f6469763e'); ?>
                    </div>
                </div>
                <script>
                    var maxGroups = <?php echo MAXGROUPS ?>;
                    var currentGroups = <?php echo $grpcount ?>;
                    var badge = document.getElementById('grpdisplay');

                    function toggleGroup(grpid) {
                        var img = document.getElementById('img_'+grpid);
                        if (img.className == 'notInGroup') {
                            $.ajax({url: "api.php?addGroup="+grpid, success: function(result){
                                if (result == 'true') {
                                    currentGroups = currentGroups + 1;
                                    updateGroups();
                                    img.className = '';
                                }
                            }});
                        } else {
                            $.ajax({url: "api.php?delGroup="+grpid, success: function(result){
                                if (result == 'true') {
                                    currentGroups = currentGroups - 1;
                                    updateGroups();
                                    img.className = 'notInGroup';
                                }
                            }});
                        }
                    }

                    function updateGroups() {
                        badge.innerHTML = currentGroups+' von '+maxGroups+' Gruppen zugewiesen.';
                    }

                    updateGroups();
                </script>
            <?php } ?>
        </div>
    </body>
    <script src="style/js/jquery.min.js"></script>
    <script src="style/js/bootstrap.min.js"></script>
    <script src="style/js/lazyload.js"></script>
</html>
