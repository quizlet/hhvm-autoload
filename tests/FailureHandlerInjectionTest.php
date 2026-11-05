<?hh // strict

namespace Facebook\AutoloadMap;

/**
 * failureHandler and devFailureHandler are concatenated into vendor/hh_autoload.php.
 * These tests are the specification for a fix: they fail while a config string can
 * still be executed or written into the generated autoloader.
 */
final class FailureHandlerInjectionTest extends \PHPUnit_Framework_TestCase {
  private vec<string> $tempDirs = vec[];

  public function tearDown(): void {
    foreach ($this->tempDirs as $dir) {
      $this->removeTree($dir);
    }
    $this->tempDirs = vec[];
  }

  public function testCliDevModeDoesNotExecuteDevFailureHandler(): void {
    $project = $this->makeProject();
    $marker = $project.'/dev-marker';
    $this->writeConfig($project, [
      'devFailureHandler' => $this->callPayload($marker),
      'failureHandler' => $this->callPayload($project.'/unused-prod-marker'),
    ]);
    $this->assertGeneratorRejectsPayload(
      'bin/hh-autoload dev mode',
      $project.'/vendor/hh_autoload.php',
      $marker,
      () ==> $this->runHhAutoload($project, false),
    );
  }

  public function testCliNoDevDoesNotExecuteFailureHandler(): void {
    $project = $this->makeProject();
    $marker = $project.'/prod-marker';
    $this->writeConfig($project, [
      'devFailureHandler' => 'Exception',
      'failureHandler' => $this->callPayload($marker),
    ]);
    $this->assertGeneratorRejectsPayload(
      'bin/hh-autoload --no-dev',
      $project.'/vendor/hh_autoload.php',
      $marker,
      () ==> $this->runHhAutoload($project, true),
    );
  }

  public function testCliDevModeUsesFailureHandlerWhenDevHandlerOmitted(): void {
    $project = $this->makeProject();
    $marker = $project.'/defaulted-marker';
    $this->writeConfig($project, [
      'failureHandler' => $this->callPayload($marker),
    ]);
    $this->assertGeneratorRejectsPayload(
      'bin/hh-autoload with devFailureHandler omitted',
      $project.'/vendor/hh_autoload.php',
      $marker,
      () ==> $this->runHhAutoload($project, false),
    );
  }

  public function testCliRejectsHandlerWithoutLeadingBackslash(): void {
    $project = $this->makeProject();
    $marker = $project.'/relative-marker';
    $this->writeConfig($project, [
      'devFailureHandler' => $this->callPayload($marker, false),
      'failureHandler' => 'Exception',
    ]);
    $this->assertGeneratorRejectsPayload(
      'bin/hh-autoload dev handler without a leading backslash',
      $project.'/vendor/hh_autoload.php',
      $marker,
      () ==> $this->runHhAutoload($project, false),
    );
  }

  public function testCliRejectsNullByteInFailureHandler(): void {
    $project = $this->makeProject();
    $marker = $project.'/null-marker';
    $this->writeConfig($project, [
      'devFailureHandler' =>
        "Exception\0; ".$this->callPayload($marker).'; //',
      'failureHandler' => 'Exception',
    ]);
    $this->assertGeneratorRejectsPayload(
      'bin/hh-autoload null byte in devFailureHandler',
      $project.'/vendor/hh_autoload.php',
      $marker,
      () ==> $this->runHhAutoload($project, false),
    );
  }

  public function testComposerDumpAutoloadDoesNotExecuteDevFailureHandler(): void {
    $project = $this->installComposerProject();
    $devMarker = $project.'/composer-dev-marker';
    $prodMarker = $project.'/composer-prod-marker';
    $this->writeConfig($project, [
      'devFailureHandler' => $this->callPayload($devMarker),
      'failureHandler' => $this->callPayload($prodMarker),
    ]);
    $this->assertGeneratorRejectsPayload(
      'composer dump-autoload',
      $project.'/vendor/hh_autoload.php',
      $devMarker,
      () ==> $this->runComposer($project, 'dump-autoload --no-interaction'),
      'Generating autoload files',
    );
    $this->assertFalse(
      \file_exists($prodMarker),
      'composer dump-autoload executed failureHandler while writing devFailureHandler',
    );
  }

  public function testComposerDumpAutoloadNoDevDoesNotExecuteFailureHandler(): void {
    $project = $this->installComposerProject();
    $devMarker = $project.'/composer-dev-marker';
    $prodMarker = $project.'/composer-prod-marker';
    $this->writeConfig($project, [
      'devFailureHandler' => $this->callPayload($devMarker),
      'failureHandler' => $this->callPayload($prodMarker),
    ]);
    $this->assertGeneratorRejectsPayload(
      'composer dump-autoload --no-dev',
      $project.'/vendor/hh_autoload.php',
      $prodMarker,
      () ==> $this->runComposer(
        $project,
        'dump-autoload --no-dev --no-interaction',
      ),
      'Generating autoload files',
    );
    $this->assertFalse(
      \file_exists($devMarker),
      'composer dump-autoload --no-dev executed devFailureHandler',
    );
  }

  public function testWriterDoesNotSpliceRawFailureHandler(): void {
    $project = $this->makeProject();
    $marker = $project.'/writer-marker';
    $destination = $project.'/hh_autoload.php';
    $config = ConfigurationLoader::fromJSON(
      \json_encode([
        'roots' => [],
        'includeVendor' => false,
        'parser' => 'ext-factparse',
        'devFailureHandler' => $this->callPayload($marker),
        'failureHandler' => $this->callPayload($marker),
      ]),
      $project.'/hh_autoload.json',
    );
    $this->assertGeneratorRejectsPayload(
      'Writer devFailureHandler from ConfigurationLoader',
      $destination,
      $marker,
      () ==> $this->runWriter($project, $destination, $config['devFailureHandler']),
    );
  }

  public function testWriterRejectsProdHandlerAndBothInsertionPoints(): void {
    $project = $this->makeProject();
    $marker = $project.'/writer-prod-marker';
    $destination = $project.'/hh_autoload.php';
    $config = ConfigurationLoader::fromJSON(
      \json_encode([
        'roots' => [],
        'includeVendor' => false,
        'parser' => 'ext-factparse',
        'failureHandler' => $this->callPayload($marker, false),
      ]),
      $project.'/hh_autoload.json',
    );
    $this->assertSame($config['failureHandler'], $config['devFailureHandler']);
    $this->assertGeneratorRejectsPayload(
      'Writer failureHandler without a leading backslash',
      $destination,
      $marker,
      () ==> $this->runWriter($project, $destination, $config['failureHandler']),
    );
  }

  public function testValidFailureHandlerIsEmittedWithVarExportAndInvoked(): void {
    $project = $this->makeProject();
    $marker = $project.'/valid-marker';
    $destination = $project.'/hh_autoload.php';
    require_once __DIR__.'/fixtures/RecordingFailureHandler.php';
    $this->writeWithHandler($project, $destination, RecordingFailureHandler::class);

    $code = \file_get_contents($destination);
    $this->assertInternalType('string', $code);
    $exported = \var_export(RecordingFailureHandler::class, true);
    $this->assertContains($exported, $code);
    $this->assertNotContains(
      RecordingFailureHandler::class.'::isEnabled',
      $code,
    );
    $this->assertNotContains(
      'new \\'.RecordingFailureHandler::class,
      $code,
    );

    $this->loadGenerated($destination, $marker);
    $recorded = \file_exists($marker) ? \file_get_contents($marker) : '';
    $this->assertContains("enabled\n", $recorded);
    $this->assertContains("constructed\n", $recorded);
  }

  public function testFailureHandlerMustBeAFailureHandlerSubclass(): void {
    $project = $this->makeProject();
    $destination = $project.'/hh_autoload.php';
    $threw = $this->generateCatching(
      () ==> {
        $this->writeWithHandler($project, $destination, Writer::class);
      },
    );
    $code = \file_exists($destination) ? \file_get_contents($destination) : '';
    $this->assertNotContains(Writer::class.'::isEnabled', $code);
    $this->assertNotContains('new \\'.Writer::class, $code);
    $this->assertTrue($threw);
    $this->assertFalse(\file_exists($destination) && \strpos($code, 'isEnabled') !== false);
  }

  public function testUnknownFailureHandlerIsRejected(): void {
    $project = $this->makeProject();
    $destination = $project.'/hh_autoload.php';
    $name = 'Facebook\\AutoloadMap\\DoesNotExistHandler';
    $threw = $this->generateCatching(
      () ==> {
        $this->writeWithHandler($project, $destination, $name);
      },
    );
    $code = \file_exists($destination) ? \file_get_contents($destination) : '';
    $this->assertNotContains($name.'::isEnabled', $code);
    $this->assertNotContains('new \\'.$name, $code);
    $this->assertTrue($threw);
  }

  private function assertGeneratorRejectsPayload(
    string $label,
    string $destination,
    string $marker,
    (function(): (int, string)) $generate,
    ?string $mustMention = null,
  ): void {
    if (\file_exists($marker)) {
      \unlink($marker);
    }
    if (\file_exists($destination)) {
      \unlink($destination);
    }
    list($code, $output) = $generate();
    $this->assertFalse(
      \file_exists($marker),
      $label.' created a payload side effect before it finished:\n'.$output,
    );
    $generated = \file_exists($destination) ? \file_get_contents($destination) : '';
    if ($generated !== '') {
      $this->loadGenerated($destination, null);
    }
    $generated = \file_exists($destination) ? \file_get_contents($destination) : '';
    $this->assertFalse(
      \file_exists($marker),
      $label." executed the failure handler payload when the generated autoloader was loaded\n".
      $this->handlerSnippet($generated),
    );
    $this->assertFalse(
      \is_string($generated) && \strpos($generated, 'file_put_contents') !== false,
      $label." wrote the failure handler string into the generated autoloader\n".
      $this->handlerSnippet($generated),
    );
    $this->assertNotSame(
      0,
      $code,
      $label." accepted the failure handler:\n".$output,
    );
    $this->assertFalse(
      \strpos($output, 'Failed to find composer autoloader') !== false,
      $output,
    );
    $this->assertFalse(
      \strpos($output, 'option does not exist') !== false,
      $output,
    );
    if ($mustMention !== null) {
      $this->assertContains($mustMention, $output);
    }
  }

  private function generateCatching((function(): void) $generate): bool {
    try {
      $generate();
      return false;
    } catch (\Throwable $e) {
      return true;
    }
  }

  private function runWriter(
    string $project,
    string $destination,
    ?string $handler,
  ): (int, string) {
    try {
      $this->writeWithHandler($project, $destination, $handler);
      return tuple(0, '');
    } catch (\Throwable $e) {
      return tuple(1, \get_class($e).': '.$e->getMessage());
    }
  }

  private function writeWithHandler(
    string $project,
    string $destination,
    ?string $handler,
  ): void {
    (new Writer())
      ->setFiles(ImmVector {})
      ->setAutoloadMap(shape(
        'class' => [],
        'function' => [],
        'type' => [],
        'constant' => [],
      ))
      ->setRoot($project)
      ->setRelativeAutoloadRoot(true)
      ->setIsDev(true)
      ->setFailureHandler($handler)
      ->writeToFile($destination);
  }

  private function loadGenerated(string $destination, ?string $validMarker): void {
    $runner = \dirname($destination).'/load-generated.php';
    $prelude = '<?hh'."\n";
    if ($validMarker !== null) {
      $prelude .=
        'require_once '.var_export($this->projectRoot().'/vendor/autoload.php', true).";\n".
        'require_once '.var_export(
          $this->projectRoot().'/tests/fixtures/RecordingFailureHandler.php',
          true,
        ).";\n".
        RecordingFailureHandler::class.'::$marker = '.var_export($validMarker, true).";\n";
    }
    $prelude .= 'require '.var_export($destination, true).";\n";
    \file_put_contents($runner, $prelude);
    $this->runProcess(
      \escapeshellarg($this->resolveExecutable('HHVM_BIN', 'hhvm')).' '.
        \escapeshellarg($runner),
      \dirname($destination),
    );
  }

  private function runHhAutoload(string $project, bool $noDev): (int, string) {
    $command = \escapeshellarg($this->resolveExecutable('HHVM_BIN', 'hhvm')).' '.
      \escapeshellarg($this->projectRoot().'/bin/hh-autoload');
    if ($noDev) {
      $command .= ' --no-dev';
    }
    return $this->runProcess($command, $project);
  }

  private function runComposer(string $project, string $args): (int, string) {
    $command = \escapeshellarg($this->resolveExecutable('HHVM_BIN', 'hhvm')).' '.
      \escapeshellarg($this->resolveExecutable('COMPOSER', 'composer')).' '.
      $args;
    return $this->runProcess($command, $project);
  }

  private function resolveExecutable(string $envName, string $name): string {
    $fromEnv = \getenv($envName);
    if (\is_string($fromEnv) && $fromEnv !== '') {
      return $fromEnv;
    }
    $path = \getenv('PATH');
    $dirs = \is_string($path) ? \explode(':', $path) : [];
    foreach ($dirs as $dir) {
      $candidate = $dir.'/'.$name;
      if ($dir !== '' && \is_file($candidate) && \is_executable($candidate)) {
        return $candidate;
      }
    }
    $this->fail($name.' was not found on PATH');
    throw new \RuntimeException($name.' was not found on PATH');
  }

  private function runProcess(string $command, string $cwd): (int, string) {
    $spec = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $proc = \proc_open($command, $spec, &$pipes, $cwd);
    if (!\is_resource($proc)) {
      $this->fail('Failed to run '.$command);
    }
    \fclose($pipes[0]);
    $stdout = \stream_get_contents($pipes[1]);
    $stderr = \stream_get_contents($pipes[2]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    $exit = \proc_close($proc);
    return tuple($exit, (string)$stdout."\n".(string)$stderr);
  }

  private function installComposerProject(): string {
    $project = $this->makeProject();
    $this->writeComposerProject($project);
    $this->writeConfig($project, []);
    list($installCode, $installOutput) = $this->runComposer(
      $project,
      'update --no-interaction --no-progress',
    );
    $this->assertSame(
      0,
      $installCode,
      "composer update with no failure handler should succeed:\n".$installOutput,
    );
    $this->assertTrue(
      \is_link($project.'/vendor/hhvm/hhvm-autoload') ||
        \is_dir($project.'/vendor/hhvm/hhvm-autoload'),
      "composer did not install hhvm-autoload:\n".$installOutput,
    );
    return $project;
  }

  private function writeComposerProject(string $project): void {
    $suppressor = $project.'/suppressor-pkg';
    $this->copyTree(
      $this->projectRoot().'/vendor/fredemmott/hack-error-suppressor',
      $suppressor,
    );
    $manifestPath = $suppressor.'/composer.json';
    $manifest = \json_decode(\file_get_contents($manifestPath), true);
    $this->assertInternalType('array', $manifest);
    $manifest['version'] = '1.0.1';
    \file_put_contents(
      $manifestPath,
      \json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n",
    );
    $composerJson = [
      'name' => 'hh-autoload/injection-fixture',
      'repositories' => [
        [
          'type' => 'path',
          'url' => $this->projectRoot(),
          'options' => ['symlink' => true],
        ],
        [
          'type' => 'path',
          'url' => $suppressor,
          'options' => ['symlink' => true],
        ],
        ['packagist' => false],
      ],
      'require' => [
        'hhvm/hhvm-autoload' => '*',
      ],
      'minimum-stability' => 'dev',
      'prefer-stable' => true,
    ];
    \file_put_contents(
      $project.'/composer.json',
      \json_encode($composerJson, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n",
    );
  }

  private function writeConfig(string $project, array<string, mixed> $extra): void {
    $data = [
      'roots' => [],
      'includeVendor' => false,
      'parser' => 'ext-factparse',
    ];
    foreach ($extra as $key => $value) {
      $data[$key] = $value;
    }
    if (!\is_dir($project.'/vendor')) {
      \mkdir($project.'/vendor');
    }
    \file_put_contents(
      $project.'/hh_autoload.json',
      \json_encode($data, \JSON_UNESCAPED_SLASHES)."\n",
    );
  }

  private function callPayload(string $marker, bool $leadingBackslash = true): string {
    $call = 'file_put_contents('.\var_export($marker, true).",'1')||\\Exception";
    if ($leadingBackslash) {
      $call = '\\'.$call;
    }
    return $call;
  }

  private function handlerSnippet(string $generated): string {
    $pos = \strpos($generated, 'if (');
    if ($pos === false) {
      return $generated;
    }
    return \substr($generated, $pos, 500);
  }

  private function makeProject(): string {
    $dir = \sys_get_temp_dir().'/hh-autoload-injection-'.\bin2hex(\random_bytes(6));
    \mkdir($dir);
    $this->tempDirs[] = $dir;
    return $dir;
  }

  private function projectRoot(): string {
    return \dirname(__DIR__);
  }

  private function copyTree(string $from, string $to): void {
    if (!\is_dir($from)) {
      $this->fail('Missing '.$from);
    }
    \mkdir($to);
    foreach (new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST,
    ) as $item) {
      $target = $to.'/'.\substr($item->getPathname(), \strlen($from) + 1);
      if ($item->isDir()) {
        \mkdir($target);
      } else {
        \copy($item->getPathname(), $target);
      }
    }
  }

  private function removeTree(string $dir): void {
    if (!\is_dir($dir)) {
      return;
    }
    $this->runProcess('rm -rf -- '.\escapeshellarg($dir), \sys_get_temp_dir());
  }
}
