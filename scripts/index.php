#!/usr/bin/env php
<?php
// zhangwei@danke.com

require __DIR__ . '/../vendor/autoload.php';

$app = new \Symfony\Component\Console\Application();
$app->add(new \Elendev\NexusComposerPush\PushCommand());
$app->setDefaultCommand('nexus-push');
$app->run();
