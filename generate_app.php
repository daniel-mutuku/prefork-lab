<?php
// Generates a fake "app" so booting it costs real memory, like a framework would:
// many classes (compiled code) plus a big config array (data).
// Usage: php generate_app.php [classes=3000]

$classes = (int) ($argv[1] ?? 3000);
$dir = __DIR__ . '/app';
@mkdir($dir);

for ($i = 0; $i < $classes; $i++) {
    $methods = '';
    for ($m = 0; $m < 10; $m++) {
        $methods .= "    public function method{$m}(array \$in): array { return array_map(fn(\$v) => \$v . '{$i}-{$m}', \$in); }\n";
    }
    file_put_contents("$dir/Service{$i}.php", "<?php\nnamespace App;\n\nclass Service{$i}\n{\n{$methods}}\n");
}

echo "Generated $classes classes in $dir\n";
