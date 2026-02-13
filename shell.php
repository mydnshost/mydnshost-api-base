#!/usr/bin/php
<?php
    global $database;
    require_once(dirname(__FILE__) . '/functions.php');

    // From: https://github.com/bobthecow/psysh/issues/353
    class GlobalsCodeCleaner extends \Psy\CodeCleaner\CodeCleanerPass {
        private static $superglobals = ['GLOBALS', '_SERVER', '_ENV', '_FILES', '_COOKIE', '_POST', '_GET', '_SESSION'];

        public function beforeTraverse(array $nodes) {
            $names = array();
            foreach (array_diff(array_keys($GLOBALS), self::$superglobals) as $name) {
                array_push($names, new \PhpParser\Node\Expr\Variable($name));
            }
            array_unshift($nodes, new \PhpParser\Node\Stmt\Global_($names));
            return $nodes;
        }

    }

    $maintenanceTraverser = new \PhpParser\NodeTraverser();
    $maintenanceTraverser->addVisitor(new GlobalsCodeCleaner());

    $maintenanceShell = new \Psy\Shell(new \Psy\Configuration(['codeCleaner' => new \Psy\CodeCleaner(null, null, $maintenanceTraverser)]));
    $maintenanceShell->run();
