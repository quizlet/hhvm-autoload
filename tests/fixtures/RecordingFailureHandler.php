<?hh // strict

namespace Facebook\AutoloadMap;

final class RecordingFailureHandler extends FailureHandler {
  public static string $marker = '';

  <<__Override>>
  public static function isEnabled(): bool {
    self::record('enabled');
    return true;
  }

  public function __construct() {
    self::record('constructed');
  }

  <<__Override>>
  public function handleFailedType(string $name): void {}

  <<__Override>>
  public function handleFailedFunction(string $name): void {}

  <<__Override>>
  public function handleFailedConstant(string $name): void {}

  private static function record(string $event): void {
    $marker = self::$marker;
    if ($marker !== '') {
      \file_put_contents($marker, $event."\n", \FILE_APPEND);
    }
  }
}
