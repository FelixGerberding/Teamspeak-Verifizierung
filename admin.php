<?php

    $error = array();
    session_start();

    function checkConnection($ip, $qport, $sport, $user, $pass, $nick) {
        require_once('libraries/TeamSpeak3/TeamSpeak3.php');
        if (strlen($nick) < 3) {
            $extension = '';
        } else {
            $extension = '&nickname='.urlencode($nick);
        }
        try {
            $ts3 = TeamSpeak3::factory(
                'serverquery://'.$user.':'.$pass.'@'.$ip.':'.$qport.'?server_port='.$sport.$extension
            );
            $ts3->clientList();
            $ts3->getInfo();
        } catch (TeamSpeak3_Exception $e) {
            return '<b>TS3 Error:</b> '.$e->getMessage();
        }
        return true;
    }

    /*
        WRITE CONFIG
    */
    function writeConfig(
        $ts_ip, $ts_qport, $ts_sport, $ts_user, $ts_pass, $ts_displayname,
        $password, $maxgroups, $rulesacceptgroup, $groupdisallow, $rules, $rulesactivate, $groups, $titel, $impressum
    ) {
        try {
            $file = fopen('config/config.php', 'w');
            fwrite(
                $file,
                '<?php'.PHP_EOL
                .'  /*'.PHP_EOL
                .'      Automatisch erstellte Config-Datei    '.PHP_EOL
                .'      Erstellt am '.date('d.m.Y  H:i:s').'    '.PHP_EOL
                .'      Fragen? felix@opossumts.net'.PHP_EOL
                .'  */'.PHP_EOL
                .'  define (\'IP\',                 \''.$ts_ip.'\');'.PHP_EOL
                .'  define (\'QUERYPORT\',          \''.$ts_qport.'\');'.PHP_EOL
                .'  define (\'SERVERPORT\',         \''.$ts_sport.'\');'.PHP_EOL
                .'  define (\'QUERYUSER\',          \''.$ts_user.'\');'.PHP_EOL
                .'  define (\'QUERYPASS\',          \''.$ts_pass.'\');'.PHP_EOL
                .'  define (\'QUERYDISPLAYNAME\',   \''.$ts_displayname.'\');'.PHP_EOL
                .'  define (\'ADMINPANEL_PASS\',    \''.$password.'\');'.PHP_EOL
                .'  define (\'MAXGROUPS\',          \''.$maxgroups.'\');'.PHP_EOL
                .'  define (\'RULESACCEPTGROUP\',   \''.$rulesacceptgroup.'\');'.PHP_EOL
                .'  define (\'GROUPSDISALLOW\',     \''.$groupdisallow.'\');'.PHP_EOL
                .'  define (\'RULES\',              \''.$rules.'\');'.PHP_EOL
                .'  define (\'RULESACTIVATE\',      \''.$rulesactivate.'\');'.PHP_EOL
                .'  define (\'GROUPS\',             \''.$groups.'\');'.PHP_EOL
                .'  define (\'SEITENTITEL\',        \''.$titel.'\');'.PHP_EOL
                .'  define (\'IMPRESSUM\',          \''.$impressum.'\');'.PHP_EOL
            );
            fclose($file);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }



    /*
        INSTALLATION AND LOGIN
    */
    if (empty($_SESSION['step']['admin'])) {
        if (!file_exists('config/config.php')) {
            $_SESSION['step']['admin'] = 'setup_password';
        } else {
            $_SESSION['step']['admin'] = 'login';
            $_SESSION['pass_try'] = 0;
        }
    } else {
        if ($_SESSION['step']['admin'] == 'login') {
            require_once('config/config.php');
            if (!empty($_POST['password']) and (hash('sha256',hash('sha256',$_POST['password'])) == ADMINPANEL_PASS)) {
                $_SESSION['step']['admin'] = 'configurator';
                $_SESSION['password'] = ADMINPANEL_PASS;
                unset($_SESSION['pass_try']);
            } else {
                $_SESSION['pass_try']++;
                if ($_SESSION['pass_try'] > 5) {
                    $_SESSION['step']['admin'] = 'pass_block';
                }
            }
        }
        if ($_SESSION['step']['admin'] == 'setup_ts3') {
            if (
                !empty($_POST['ts_ip'])
                AND !empty($_POST['ts_qport'])
                AND !empty($_POST['ts_sport'])
                AND !empty($_POST['ts_user'])
                AND !empty($_POST['ts_pass'])
            ) {
                $ts3_conn_status = checkConnection($_POST['ts_ip'], $_POST['ts_qport'], $_POST['ts_sport'], $_POST['ts_user'], $_POST['ts_pass'], $_POST['ts_displayname']);
                if ($ts3_conn_status === TRUE) {
                    $_SESSION['ts']['ip'] = $_POST['ts_ip'];
                    $_SESSION['ts']['qport'] = $_POST['ts_qport'];
                    $_SESSION['ts']['sport'] = $_POST['ts_sport'];
                    $_SESSION['ts']['user'] = $_POST['ts_user'];
                    $_SESSION['ts']['pass'] = $_POST['ts_pass'];
                    $_SESSION['ts']['displayname'] =
                    $_SESSION['impressum'] = $_POST['impressum'];
                    $_SESSION['seitentitel'] = $_POST['seitentitel'];
                    $_SESSION['step']['admin'] = 'setup_complete';
                } else {
                    $error[] = array('danger', $ts3_conn_status);
                }
            } else {
                $error[] = array('info', 'Bitte fülle alle Felder aus!');
            }
        }
        if ($_SESSION['step']['admin'] == 'setup_complete') {
            $setup_steps = array();
            $ts3status = checkConnection($_SESSION['ts']['ip'], $_SESSION['ts']['qport'], $_SESSION['ts']['sport'], $_SESSION['ts']['user'], $_SESSION['ts']['pass'], $_SESSION['ts']['displayname']);
            if ($ts3status !== TRUE) {
                $error[] = ['danger', $ts3status];
            }
            $setup_steps[] = array(
                'text' => 'TeamSpeak-Verbindung',
                'status' => $ts3status
            );

            $setup_steps[] = array(
                'text' => "Config-Ordner beschreibbar (chmod 777)",
                'status' => is_writable('config/')
            );
            $setup_steps[] = array(
                'text' => 'Icon-Ordner beschreibbar (chmod 777)',
                'status' => is_writable('icons/')
            );
            if (!empty($_GET['complete'])) {
                foreach ($setup_steps as $col) {
                    if ($col['status'] !== TRUE) {
                        break;
                    }
                }
                if ($col['status'] === TRUE) {
                    if (
                        !writeConfig(
                            $_SESSION['ts']['ip'],
                            $_SESSION['ts']['qport'],
                            $_SESSION['ts']['sport'],
                            $_SESSION['ts']['user'],
                            $_SESSION['ts']['pass'],
                            $_SESSION['ts']['displayname'],
                            $_SESSION['main_password'],
                            4,
                            7,
                            '[]',
                            '[]',
                            'false',
                            '[]',
                            $_SESSION['seitentitel'],
                            $_SESSION['impressum']
                        )
                    ) {
                        $error[] = array('danger', 'Die Config-Datei konnte nicht erstellt werden, bitte überprüfe die Dateirechte.');
                    } else {
                        $password = $_SESSION['main_password'];
                        session_unset();
                        $_SESSION['step']['admin'] = 'configurator';
                        $_SESSION['password'] = $password;
                    }
                } else {
                    $error[] = array('danger', 'Status ungültig!');
                }
            }
        }
        if ($_SESSION['step']['admin'] == 'setup_password') {
            if (!empty($_POST['password']) && !empty($_POST['password_confirm'])) {
                if ($_POST['password'] == $_POST['password_confirm']) {
                    if (strlen($_POST['password']) > 4) {
                        $_SESSION['step']['admin'] = 'setup_ts3';
                        $_SESSION['main_password'] = hash('SHA256', hash('SHA256', $_POST['password']));
                    } else {
                        $error[] = array('danger','Das Passwort muss mindestens 5 Zeichen lang sein.');
                    }
                } else {
                    $error[] = array('danger','Die eingegebenen Passwörter stimmen nicht überein.');
                }
            }
        }
    }






    /*
        STUFF FOR THE MAIN CONFIGURATOR
    */
    if ($_SESSION['step']['admin'] == 'configurator') {
        require_once('libraries/TeamSpeak3/TeamSpeak3.php');
        require_once('config/config.php');
        $do = '';
        if (!empty($_POST['form_location'])) {
            switch ($_POST['form_location']) {
                case 'groups':
                    $newGroups = array();
                    foreach (explode(',', $_POST['icn_active']) as $icon) {
                        if (is_numeric($icon)) {
                            $newGroups[] = $icon;
                        }
                    }
                    if (
                        !writeConfig(
                            IP,
                            QUERYPORT,
                            SERVERPORT,
                            QUERYUSER,
                            QUERYPASS,
                            QUERYDISPLAYNAME,
                            ADMINPANEL_PASS,
                            MAXGROUPS,
                            RULESACCEPTGROUP,
                            GROUPSDISALLOW,
                            RULES,
                            RULESACTIVATE,
                            SEITENTITEL,
                            IMPRESSUM,
                            json_encode($newGroups)
                        )
                    ) {
                        $error[] = array('danger', 'Fehlerhafte Dateirechte, bitte setzt die Rechte auf 777!');
                    } else {
                        header("Refresh:0");
                        die();
                    }
                    break;

                case 'editTS3Config':
                    if (
                        !empty($_POST['ts_ip'])
                        AND !empty($_POST['ts_qport'])
                        AND !empty($_POST['ts_sport'])
                        AND !empty($_POST['ts_user'])
                        AND !empty($_POST['ts_pass'])
                    ) {
                        $ts3_conn_status = checkConnection($_POST['ts_ip'], $_POST['ts_qport'], $_POST['ts_sport'], $_POST['ts_user'], $_POST['ts_pass'], $_POST['ts_displayname']);
                        if ($ts3_conn_status === TRUE) {
                            if (
                                !writeConfig(
                                    IP,
                                    QUERYPORT,
                                    SERVERPORT,
                                    QUERYUSER,
                                    QUERYPASS,
                                    QUERYDISPLAYNAME,
                                    ADMINPANEL_PASS,
                                    MAXGROUPS,
                                    RULESACCEPTGROUP,
                                    GROUPSDISALLOW,
                                    RULES,
                                    RULESACTIVATE,
                                    GROUPS,
                                    SEITENTITEL,
                                    IMPRESSUM
                                )
                            ) {
                                $error[] = array('danger', 'Fehlerhafte Dateirechte, bitte setzt die Rechte auf 777!');
                            } else {
                                header("Refresh:0");
                                die();
                            }
                        } else {
                            $error[] = array('danger', $ts3_conn_status);
                        }
                    } else {
                        $error[] = array('info', 'Bitte fülle alle nötigen Felder aus!');
                    }
                    break;
                case 'addrule':
                    if (strlen($_POST['rule']) > 3) {
                        $rules = json_decode(RULES);
                        array_push($rules ,$_POST['rule']);
                        $rules = json_encode($rules, 1);
                        if (
                            !writeConfig(
                                IP,
                                QUERYPORT,
                                SERVERPORT,
                                QUERYUSER,
                                QUERYPASS,
                                QUERYDISPLAYNAME,
                                ADMINPANEL_PASS,
                                MAXGROUPS,
                                RULESACCEPTGROUP,
                                GROUPSDISALLOW,
                                $rules,
                                RULESACTIVATE,
                                GROUPS,
                                SEITENTITEL,
                                IMPRESSUM
                            )
                        ) {
                            $error[] = array('danger', 'Fehlerhafte Dateirechte, bitte setzt die Rechte auf 777!');
                        } else {
                            header("Refresh:0");
                            die();
                        }
                    } else {
                        $error[] = array('danger', 'Rules Text to Short!');
                    }
                    break;
                case 'deleterule':
                    $rules = json_decode(RULES);
                    $newrules = array();
                    foreach ($rules as $index => $rule) {
                        if (!in_array($index, $_POST['rules'])) $newrules[] = $rule;
                    }
                    $newrules = json_encode($newrules, 1);
                    if (
                        !writeConfig(
                            IP,
                            QUERYPORT,
                            SERVERPORT,
                            QUERYUSER,
                            QUERYPASS,
                            QUERYDISPLAYNAME,
                            ADMINPANEL_PASS,
                            MAXGROUPS,
                            RULESACCEPTGROUP,
                            GROUPSDISALLOW,
                            $newrules,
                            RULESACTIVATE,
                            GROUPS,
                            SEITENTITEL,
                            IMPRESSUM
                        )
                    ) {
                        $error[] = array('danger', 'Fehlerhafte Dateirechte, bitte setzt die Rechte auf 777!');
                    } else {
                        header("Refresh:0");
                        die();
                    }
                    break;
                case 'generalsettings':
                    if (is_numeric($_POST['maxgroups'])) {
                        $maxgroups = $_POST['maxgroups'];
                    } else {
                        $maxgroups = 4;
                    }
                    if (is_numeric($_POST['rulesacceptgroup'])) {
                        $rulesacceptgroup = $_POST['rulesacceptgroup'];
                    } else {
                        $rulesacceptgroup = 0;
                    }
                    if (empty($_POST['groupsdisallow'])) {
                        $groupsdisallow = '[]';
                    } else {
                        $groupsdisallow = json_encode($_POST['groupsdisallow'],1);
                    }
                    if (empty($_POST['enablerules']) and !$_POST['enablerules']) {
                        $enablerules = false;
                    } else {
                        $enablerules = true;
                    }
                    if (
                        !writeConfig(
                            IP,
                            QUERYPORT,
                            SERVERPORT,
                            QUERYUSER,
                            QUERYPASS,
                            QUERYDISPLAYNAME,
                            ADMINPANEL_PASS,
                            $maxgroups,
                            $rulesacceptgroup,
                            $groupsdisallow,
                            RULES,
                            $enablerules,
                            GROUPS,
                            SEITENTITEL,
                            IMPRESSUM
                        )
                    ) {
                        $error[] = array('danger', 'Fehlerhafte Dateirechte, bitte setzt die Rechte auf 777!');
                    } else {
                        header("Refresh:0");
                        die();
                    }
                    break;
            }
        }
        if (!empty($_GET['seite'])) {
            switch ($_GET['seite']) {
                case 'rules':
                    $do = 'rules';
                    $rules = json_decode(RULES);
                    break;

                case 'ts3connection':
                    $do = 'ts3connection';
                    break;

                case 'einstellungen':
                    $do = 'einstellungen';
                    $ts3 = TeamSpeak3::factory("serverquery://".QUERYUSER.":".QUERYPASS."@".IP.":".QUERYPORT."/?server_port=".SERVERPORT);
                    $groups = $ts3->servergroupList(array('type' => 1));
                    break;

                case 'groups':
                    $icons = array();
                    $icons = json_decode(file_get_contents('config/icons.json'),1);
                    $do = 'groups';
                    $ts3 = TeamSpeak3::factory("serverquery://".QUERYUSER.":".QUERYPASS."@".IP.":".QUERYPORT."/?server_port=".SERVERPORT);
                    $groups = $ts3->servergroupList(array('type' => 1));
                    break;
            }
        } else {
            $do = 'einstellungen';
            $ts3 = TeamSpeak3::factory("serverquery://".QUERYUSER.":".QUERYPASS."@".IP.":".QUERYPORT."/?server_port=".SERVERPORT);
            $groups = $ts3->servergroupList(array('type' => 1));
        }
    }


?>
<html>
    <head>
        <title>Admin-Interface | TeamSpeak-Verifizierung</title>
        <link href="style/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body>
        <div class="row-fluid">
        <?php foreach ($error as $e) { ?>
            <div class="alert alert-<?php echo $e[0]; ?>" role="alert"><?php echo $e[1]; ?></div>
        <?php } ?>
        <?php if ($_SESSION['step']['admin'] == 'configurator') { ?>
                <div style="margin-top:5%;" class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-lg-6 col-sm-offset-3">
                    <div class="container">
                        <div class="btn-group" style="margin-bottom:15px;">
                            <a href="admin.php?seite=einstellungen" class="btn btn-info">Einstellungen</a>
                            <a href="admin.php?seite=ts3connection" class="btn btn-primary">TeamSpeak-Daten</a>
                            <a href="admin.php?seite=groups" class="btn btn-primary">Gruppen</a>
                            <a href="#" id="iconSync" onClick="iconSync(this)" class="btn btn-primary">Icons synchronisieren</a>
                        </div>
                    </div>
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">TeamSpeak-Verifizierung</font></b></h5>
                        </div>
                        <div class="panel-body">
                            <?php if ($do == 'einstellungen') { ?>
                                <div class="page-header" style="margin-top:-2%">
                                    <h1><small>Einstellungen</small></h1>
                                </div>
                                <form action="" method="POST" class="form-horizontal">
                                    <div class="form-group">
                                        <label for="maxgroups" class="col-sm-5 control-label">Maximalanzahl an Gruppen</label>
                                        <div class="col-sm-6">
                                            <input type="number" class="form-control" name="maxgroups" id="maxgroups" placeholder="Teamspeak IP" value="<?php echo MAXGROUPS ?>">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="enablerules" class="col-sm-5 control-label">Regelsystem aktivieren/deaktivieren</label>
                                        <div class="col-sm-6">
                                            <select class="form-control" name="enablerules" id="enablerules">
                                                <option value="0">Deaktivieren</option>
                                                <option value="1" <?php if (RULESACTIVATE) echo 'selected'; ?>>Aktivieren</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="ts_qport" class="col-sm-5 control-label">Gruppe beim Akzeptieren der Regeln zuweisen</label>
                                        <div class="col-sm-6">
                                            <select class="form-control" name="rulesacceptgroup" id="rulesacceptgroup">
                                                <option value="0">Keine Gruppe hinzufügen</option>
                                                <?php foreach ($groups as $grp) { ?>
                                                    <option value="<?php echo $grp->sgid; ?>" <?php if ($grp->sgid == RULESACCEPTGROUP) echo 'selected' ?>><?php echo $grp->name; ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="maxgroups" class="col-sm-5 control-label">Gruppen die die Iconzuweisung nicht verwenden dürfen</label>
                                        <div class="col-sm-6">
                                            <select multiple class="form-control" name="groupsdisallow[]" id="groupsdisallow[]" size="10">
                                                <?php foreach ($groups as $grp) { ?>
                                                    <option value="<?php echo $grp->sgid; ?>" <?php if (in_array($grp->sgid, json_decode(GROUPSDISALLOW))) echo 'selected' ?>><?php echo $grp->name; ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <div class="col-sm-offset-5 col-sm-6">
                                            <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
                                        </div>
                                    </div>
                                    <input type="hidden" name="form_location" id="form_location" value="generalsettings" />
                                </form>
                            <?php } ?>
                            <?php if ($do == 'ts3connection') { ?>
                                <div class="page-header" style="margin-top:-2%">
                                    <h1><small>Teamspeak-Daten</small></h1>
                                </div>
                                <form action="" method="POST" class="form-horizontal">
                                    <div class="form-group">
                                        <label for="ts_ip" class="col-sm-4 control-label">Teamspeak IP <font color="red">*</font></label>
                                        <div class="col-sm-7">
                                            <input type="text" class="form-control" name="ts_ip" id="ts_ip" placeholder="Teamspeak IP" value="<?php echo IP ?>">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="ts_qport" class="col-sm-4 control-label">Query-Port <font color="red">*</font></label>
                                        <div class="col-sm-7">
                                            <input type="text" class="form-control" name="ts_qport" id="ts_qport" placeholder="Query-Port" value="<?php echo QUERYPORT ?>">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="ts_sport" class="col-sm-4 control-label">Server-Port <font color="red">*</font></label>
                                        <div class="col-sm-7">
                                            <input type="text" class="form-control" name="ts_sport" id="ts_sport" placeholder="Server-Port" value="<?php echo SERVERPORT ?>">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="ts_user" class="col-sm-4 control-label">Query-Username <font color="red">*</font></label>
                                        <div class="col-sm-7">
                                            <input type="text" class="form-control" name="ts_user" id="ts_user" placeholder="serveradmin" value="<?php echo QUERYUSER ?>">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="ts_pass" class="col-sm-4 control-label">Query-Passwort <font color="red">*</font></label>
                                        <div class="col-sm-7">
                                            <input type="text" class="form-control" name="ts_pass" id="ts_pass" placeholder="Password" value="<?php echo QUERYPASS ?>">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="ts_pass" class="col-sm-4 control-label">Sichtbarer Name der Query</label>
                                        <div class="col-sm-7">
                                            <input type="text" class="form-control" name="ts_displayname" id="ts_displayname" placeholder="Displayname" value="<?php echo QUERYDISPLAYNAME ?>">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <div class="col-sm-offset-4 col-sm-7">
                                            <font color="red">*</font> benötigtete Felder
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <div class="col-sm-offset-4 col-sm-7">
                                            <button type="submit" class="btn btn-primary">Speichern & Verbindung testen</button>
                                        </div>
                                    </div>
                                    <input type="hidden" value="editTS3Config" name="form_location" id="form_location">
                                </form>
                            <?php } ?>
                            <?php if ($do == 'groups') { ?>
                                <style>
                                    .notInGroup {
                                        opacity: 0.1;
                                    }
                                </style>
                                <div class="page-header" style="margin-top:-3%; margin-bottom:5%">
                                    <h1><small>Lege zuweisbare Icons fest</small></h1>
                                </div>
                                <div class="alert alert-info" role="alert">Es können nur Servergruppen mit einem Icon ausgewählt werden.</div>
                                <?php if (is_array($icons)) { ?>
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
                                    ?>
                                    <?php foreach ($groups as $grp) { ?>
                                        <?php if ($i % 2 == 0) echo '<tr>'; ?>
                                            <?php if (array_key_exists($grp->sgid, $icons)) { ?>
                                            <td onclick="toggleGroup(<?php echo $grp->sgid; ?>)"><img <?php if (!in_array($grp->sgid, $active)) echo 'class="notInGroup"'; ?> id="img_<?php echo $grp->sgid ?>" height="16" width="16" src="<?php echo $icons[$grp->sgid] ?>"></img></td>
                                            <td onclick="toggleGroup(<?php echo $grp->sgid; ?>)"><button class="btn btn-xs"><?php echo $grp->name.' ('.$grp->sgid.')' ?></button></td>
                                        <?php if ($i % 2 == 1) echo '</tr>'; ?>
                                    <?php $i++;}} ?>
                                </table>
                                <form action="" method="POST">
                                    <input type="hidden" name="icn_active" id="icn_active" value="<?php echo implode(',',$active) ?>">
                                    <input type="hidden" name="form_location" id="form_location" value="groups">
                                    <input type="submit" class="pull-right btn btn-primary" value="Gruppen speichern"></input>
                                </form>
                                <?php } else { ?>
                                    Du musst erst die Icons synchronisieren
                                <?php } ?>
                            <?php } ?>
                        </div>
                    </div>
                    <div class="container">
                        <?php echo hex2bin('436f70797269676874203c6120687265663d22687474703a2f2f6d756c7469766974616d696e2e777466223e6d756c7469766974616d696e2e7774663c2f613e'); ?>
                    </div>
                </div>
                <script>
                    function toggleGroup(grpid) {
                        var form = document.getElementById("icn_active");
                        var activeicons = form.value.split(',');
                        var img = document.getElementById('img_'+grpid);
                        if (img.className == 'notInGroup') {
                            if (form.value == '') {
                                form.value = grpid;
                            } else {
                                activeicons.push(grpid);
                                form.value = activeicons.join();
                            }
                            img.className = '';
                        } else {
                            var index = activeicons.indexOf(grpid.toString());
                            if (index >= 0) {
                                activeicons.splice(index, 1);
                                form.value = activeicons.join();
                                img.className = 'notInGroup';
                            }
                        }
                    }
                </script>
                <script>
                    var sync = false;

                    function iconSync(button) {
                        if (!sync) {
                            sync = true;
                            button.disabled = true;
                            button.innerHTML = 'Lade Icons herrunter ...';
                            $.ajax({url: "api.php?syncIcons=1", success: function(result){
                                if (result == 'true') {
                                    button.innerHTML = 'Icons synchronisiert';
                                } else {
                                    button.innerHTML = 'Konnte Icons nicht synchronisieren';
                                }
                                sync = false;
                            }});
                        }
                    };
                </script>
            <?php } ?>
            <?php if ($_SESSION['step']['admin'] == 'pass_block') { ?>
                <div style="margin-top:5%;" class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-lg-6 col-sm-offset-3">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">Falsches Passwort</font></b></h5>
                        </div>
                        <div class="panel-body">
                            Zuviele Versuche
                        </div>
                    </div>
                </div>
            <?php } ?>
            <?php if ($_SESSION['step']['admin'] == 'setup_complete') { ?>
                <div style="margin-top:5%;" class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-lg-6 col-sm-offset-3">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">Installation erfolgreich</font></b></h5>
                        </div>
                        <div class="panel-body">
                            <ul class="list-group">
                            <?php $fail = false; foreach ($setup_steps as $step) { ?>
                                <li class="list-group-item">
                                <span class="badge"><?php if ($step['status'] === TRUE) { echo 'OK'; } else { echo 'FAIL'; $fail = true;} ?></span>
                                    <?php echo $step['text'] ?>
                                </li>
                            <?php } ?>
                            <a href="?complete=true" style="margin-top:4%;" <?php if($fail) { echo 'disabled'; } ?> class="btn btn-primary">Einrichtung abschließen</a>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php } ?>
            <?php if ($_SESSION['step']['admin'] == 'setup_password') { ?>
                <div style="margin-top:5%;" class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-lg-6 col-sm-offset-3">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">TeamSpeak-Verifizierung</font></b></h5>
                        </div>
                        <div class="page-header" style="margin:20px;">
                            <h1><small>Passwort für das Interface</small></h1>
                        </div>
                        <div class="panel-body">
                            <form action="" method="POST" class="form-horizontal">
                                <div class="form-group">
                                    <label for="password" class="col-sm-4 control-label">Passwort</label>
                                    <div class="col-sm-7">
                                        <input type="password" class="form-control" name="password" id="password" placeholder="Passwort">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="password_confirm" class="col-sm-4 control-label">Passwort bestätigen</label>
                                    <div class="col-sm-7">
                                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" placeholder="Passwort bestätigen">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="col-sm-offset-4 col-sm-7">
                                        <button type="submit" class="btn btn-primary">Weiter</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php } ?>
            <?php if ($_SESSION['step']['admin'] == 'setup_ts3') { ?>
                <div style="margin-top:5%;" class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-lg-6 col-sm-offset-3">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">Einrichtung</font></b></h5>
                        </div>
                        <div class="panel-body">
                            <form action="" method="POST" class="form-horizontal">
                                <div class="form-group">
                                    <label for="ts_ip" class="col-sm-4 control-label">Teamspeak IP <font color="red">*</font></label>
                                    <div class="col-sm-7">
                                        <input type="text" class="form-control" name="ts_ip" id="ts_ip" placeholder="Teamspeak IP">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="ts_qport" class="col-sm-4 control-label">Query-Port <font color="red">*</font></label>
                                    <div class="col-sm-7">
                                        <input type="text" class="form-control" name="ts_qport" id="ts_qport" placeholder="10011">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="ts_sport" class="col-sm-4 control-label">Server-Port <font color="red">*</font></label>
                                    <div class="col-sm-7">
                                        <input type="text" class="form-control" name="ts_sport" id="ts_sport" placeholder="9987">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="ts_user" class="col-sm-4 control-label">Query-Username <font color="red">*</font></label>
                                    <div class="col-sm-7">
                                        <input type="text" class="form-control" name="ts_user" id="ts_user" placeholder="serveradmin">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="ts_pass" class="col-sm-4 control-label">Query-Passwort <font color="red">*</font></label>
                                    <div class="col-sm-7">
                                        <input type="text" class="form-control" name="ts_pass" id="ts_pass" placeholder="Passwort">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="ts_displayname" class="col-sm-4 control-label">Sichtbarer Name der Query</label>
                                    <div class="col-sm-7">
                                        <input type="text" class="form-control" name="ts_displayname" id="ts_displayname" placeholder="Teamspeak-Verifizierung">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="impressum" class="col-sm-4 control-label">Link zum Impressum</label>
                                    <div class="col-sm-7">
                                        <input type="text" class="form-control" name="impressum" id="impressum" placeholder="https://www.opossumts.net/legal-notice">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="seitentitel" class="col-sm-4 control-label">Titel der Seite im Browser</label>
                                    <div class="col-sm-7">
                                        <input type="text" class="form-control" name="seitentitel" id="seitentitel" placeholder="TeamSpeak-Verifizierung">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="col-sm-offset-4 col-sm-7">
                                        <font color="red">*</font> benötigte Felder
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="col-sm-offset-4 col-sm-7">
                                        <button type="submit" class="btn btn-primary">Weiter</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php } ?>
            <?php if ($_SESSION['step']['admin'] == 'login') { ?>
                <div style="margin-top:5%;" class="col-md-3 col-md-offset-4">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">Login</font></b></h5>
                        </div>
                        <div class="panel-body">
                            <form action="" method="POST" class="form-horizontal">
                                <div class="form-group">
                                    <label for="password" class="col-sm-3 control-label">Passwort</label>
                                    <div class="col-sm-9">
                                        <input type="password" class="form-control" name="password" id="password" placeholder="Passwort">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="col-sm-offset-3 col-sm-9">
                                        <button type="submit" class="btn btn-primary">Login</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
    </body>
    <script src="style/js/jquery.min.js"></script>
    <script src="style/js/bootstrap.min.js"></script>
</html>
