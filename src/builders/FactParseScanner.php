<?hh // strict
/*
 *  Copyright (c) 2015-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

namespace Facebook\AutoloadMap;

use Facebook\AutoloadMap\__Private\TypeAssert;

/** Create an autoload map from a directory using `ext_factparse`. */
final class FactParseScanner implements Builder {
  const int MAX_FILES_PER_TREE = 4096;

  const type TFacts = array<string, shape(
    'types' => array<shape(
      'name' => string,
    )>,
    'constants' => array<string>,
    'functions' => array<string>,
    'typeAliases' => array<string>,
  )>;

  private static function untypedToShape(
    mixed $data,
  ): self::TFacts {
    invariant(
      is_array($data),
      'FactsParse did not give us an array',
    );

    $out = array();
    foreach ($data as $file => $facts) {
      invariant(
        \is_string($file),
        'FactsParse data is not string-keyed',
      );

      try {
        $out[$file] = shape(
          'types' => TypeAssert\is_array_of_shapes_with_name_field(
            $facts['types'] ?? null,
            'FactParse types',
          ),
          'constants' => TypeAssert\is_array_of_strings(
            $facts['constants'] ?? null,
            'FactParse constants',
          ),
          'functions' => TypeAssert\is_array_of_strings(
            $facts['functions'] ?? null,
            'FactParse functions',
          ),
          'typeAliases' => TypeAssert\is_array_of_strings(
            $facts['typeAliases'] ?? null,
            'FactParse typeAliases',
          ),
        );
      } catch (\Exception $e) {
        if (@\filesize($file) === 0) {
          continue;
        }
        throw new \Exception(
          "Failed to parse '".$file.'"',
          $e->getCode(),
          $e,
        );
      }
    }
    return $out;
  }

  private function __construct(
    private string $root,
    private ImmVector<string> $paths,
  ) {
    $version = (int) \phpversion('factparse');
    invariant(
      $version === 3,
      'Factparse version 3 is required, got %d',
      $version,
    );
  }

  public static function fromFile(
    string $path,
  ): Builder {
    return new FactParseScanner('', ImmVector { $path });
  }

  /** Scan a directory tree.
   *
   * Directory symlinks are not followed, files that resolve outside of the
   * tree are ignored, unreadable directories are skipped, and at most
   * `MAX_FILES_PER_TREE` files are recorded. Recorded paths are canonical.
   */
  public static function fromTree(
    string $root,
  ): Builder {
    $real_root = \realpath($root);
    if (!\is_string($real_root) || !\is_dir($real_root)) {
      throw new \UnexpectedValueException(
        $root.' is not a directory',
      );
    }

    $paths = Vector { };
    try {
      $rdi = new \RecursiveDirectoryIterator(
        $real_root,
        \FilesystemIterator::SKIP_DOTS,
      );
      $rii = new \RecursiveIteratorIterator(
        $rdi,
        \RecursiveIteratorIterator::LEAVES_ONLY,
        \RecursiveIteratorIterator::CATCH_GET_CHILD,
      );
      foreach ($rii as $info) {
        if (!$info->isFile()) {
          continue;
        }
        if (!$info->isReadable()) {
          continue;
        }
        $ext = $info->getExtension();
        if ($ext !== 'php' && $ext !== 'hh' && $ext !== 'xhp') {
          continue;
        }
        $path = \realpath($info->getPathname());
        if (!\is_string($path) || !Scanner::isPathInside($path, $real_root)) {
          continue;
        }
        if ($paths->count() >= self::MAX_FILES_PER_TREE) {
          \fprintf(
            \STDERR,
            "hhvm-autoload: %s contains more than %d files; ignoring the rest\n",
            $real_root,
            self::MAX_FILES_PER_TREE,
          );
          break;
        }
        $paths[] = $path;
      }
    } catch (\UnexpectedValueException $e) {
      // Unreadable directory; keep whatever was found before it.
    }

    return new FactParseScanner($real_root, $paths->immutable());
  }

  public function getAutoloadMap(): AutoloadMap {
    /* HH_FIXME[2049] no HHI for \HH\facts_parse */
    /* HH_FIXME[4107] no HHI for \HH|facts_parse */
    $facts = \HH\facts_parse(
      $this->root,
      $this->paths->toArray(),
      /* force_hh = */ false,
      /* multithreaded = */ true,
    );
    $facts = self::untypedToShape($facts);

    $classes = [];
    $functions = [];
    $types = [];
    $constants = [];
    foreach ($facts as $file => $file_facts) {
      foreach ($file_facts['types'] as $type) {
        $classes[\strtolower($type['name'])] = $file;
      }
      foreach ($file_facts['constants'] as $const) {
        $constants[$const] = $file;
      }
      foreach ($file_facts['functions'] as $func) {
        $functions[\strtolower($func)] = $file;
      }
      foreach ($file_facts['typeAliases'] as $alias) {
        $types[\strtolower($alias)] = $file;
      }
    }
    return shape(
      'class' => $classes,
      'function' => $functions,
      'type' => $types,
      'constant' => $constants,
    );
  }

  public function getFiles(): ImmVector<string> {
    return ImmVector { };
  }
}
