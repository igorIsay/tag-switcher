<?php

require_once('logger.php');
require_once('config.php');

if (!array_key_exists('patterns', $config) or count($config['patterns']) === 0) {
    Log::error('config patterns is empty');
}

if (!array_key_exists('limit', $config)) {
    Log::error('config limit is empty');
}

if (!array_key_exists('databases', $config) or count($config['databases']) === 0) {
    Log::error('config databases is empty');
}

$limit = $config['limit'];


function getRegex ($tagName) {
    return "/(<{$tagName})(?!\<)(.*)(?=\<)(?<!\\>)/";
}

function replaceH2($text, $pattern) {
    $matches = null;
    preg_match_all(getRegex('h2'), $text, $matches);
    $from = [];
    $to = [];
    foreach ($matches[0] as $key => $match) {
        $replaceTo = null;
        $replaceToTag = $pattern[$key % count($pattern)];

        switch ($replaceToTag) {
            case 'h3':
                $replaceTo = "<h3{$matches[2][$key]}</h3>";
                break;
            case 'h4':
                $replaceTo = "<h4{$matches[2][$key]}</h4>";
                break;
            case 'p + strong':
                $replaceTo = "<p><strong{$matches[2][$key]}</strong></p>";
                break;
        }

        if ($replaceTo !== null) {
            array_push($from, $match . '</h2>');
            array_push($to, $replaceTo);
        }
    }
    return str_replace($from, $to, $text);
}



foreach ($config['databases'] as $db) {
    $mysqli = new mysqli($db['host'], $db['username'], $db['password'], $db['database'], $db['port']);
    if ($mysqli->connect_errno) {
        throw new RuntimeException('ошибка соединения mysqli: ' . $mysqli->connect_error);
    }

    $idsFilename = "{$db['database']}.ids.csv";

    if (!file_exists($idsFilename)) {
        $result = $mysqli->query('SELECT ID FROM wp_posts ORDER BY ID desc;');

        $logEveryPercent = 10;
        $rowsCount = $result->num_rows;
        $onePercent =  ceil($rowsCount / 100);

        $percents = [];
        $i = 0;
        while ($i < 100) {
            $i = $i + $logEveryPercent;
            $percents[$rowsCount - $onePercent * $i] = $i;
        }

        $fp = fopen($idsFilename, 'w');

        try {
            foreach ($result as $key => $row) {
                $logPercent = null;
                if (array_key_exists($key, $percents)) {
                    $logPercent = $percents[$key];
                }
                fputcsv($fp, [$row['ID'], $logPercent]);
            }
        } catch (Exception $e) {
            print($e->getMessage);
            unlink($idsFilename);
        }
        Log::debug("{$idsFilename} created");
    }

    Log::info("{$idsFilename} processing");

    while (true) {

        ob_start();
        passthru("/bin/bash -c 'tail -n {$limit} {$idsFilename}'");
        $var = ob_get_contents();
        ob_end_clean();
        $ids = array_filter(
            explode("\n", $var),
            function ($v) {
                return strlen($v) > 0;
            }
        );

        if (count($ids) === 0) {
            $mysqli->close();
            Log::info('progress: 100%');
            break;
        }

        $inCondition = [];
        foreach ($ids as $rawId) {
            $idInfo = explode(',', $rawId);
            array_push($inCondition, $idInfo[0]);

            if (strlen($idInfo[1]) > 0) {
                Log::info("progress: {$idInfo[1]}%");
            }
        }

        $inCondition = join(',', $inCondition);
        $result = $mysqli->query("select ID, post_content from wp_posts where ID in ({$inCondition})");

        foreach ($result as $row) {
            $matches = null;
            preg_match(getRegex('h3'), $row['post_content'], $matches);
            if (count($matches) === 0) {
                $replaceTo = mysqli_real_escape_string($mysqli, replaceH2($row['post_content'], $config['patterns'][array_rand($config['patterns'])]));
                $mysqli->query("UPDATE wp_posts SET post_content = '{$replaceTo}' WHERE ID = {$row['ID']}");
            }
        }

        exec("/bin/bash -c 'tail -n {$limit} {$idsFilename} | wc -c | xargs -I {} truncate {$idsFilename} -s -{}'");
    }
}
