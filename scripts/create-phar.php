<?php
// zhangwei@danke.com
// The php.ini setting phar.readonly must be set to 0
$pharFile = __DIR__ . '/../dist/nexus-push.phar';

// clean up
if (file_exists($pharFile)) {
    unlink($pharFile);
}
if (file_exists($pharFile . '.gz')) {
    unlink($pharFile . '.gz');
}

// create phar
$p = new Phar($pharFile);

// creating our library using whole directory
$p->buildFromDirectory(__DIR__ . '/../');

// pointing main file which requires all classes
$p->setDefaultStub('scripts/index.php', 'scripts/index.php');

// plus - compressing it into gzip
$p->compress(Phar::GZ);


echo "nexus-push.phar successfully created\n";
