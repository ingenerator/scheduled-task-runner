#!/usr/bin/env php
<?php
$sleep_time = parse_arg('sleep-ms', '\d+', 1);
$name = parse_arg('name', '[\w\-\d]+', 2);
$write_file = isset($argv[3]) ? parse_arg('write-file', '.+?', 3) : false;

echo "($name) Starting - will sleep {$sleep_time}ms\n";
echo '{"severity":"INFO","message":"I am a log from '.$name.'"}'."\n";
usleep($sleep_time * 1000);
if ($write_file) {
    file_put_contents($write_file, 'Wrote from '.$name);
}
echo "($name) Done\n";


function parse_arg(string $name, string $pattern, int $arg_idx): string
{
    global $argv;
    $regex = "/^--$name=($pattern)$/";
    if (preg_match($regex, $argv[$arg_idx] ?? '', $matches)) {
        return $matches[1];
    }
    throw new \InvalidArgumentException("Pass --$name=($pattern) as argument $arg_idx");
}
