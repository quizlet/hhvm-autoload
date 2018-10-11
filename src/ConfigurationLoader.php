<?hh // strict
/*
 *  Copyright (c) 2015-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the BSD-style license found in the
 *  LICENSE file in the root directory of this source tree. An additional grant
 *  of patent rights can be found in the PATENTS file in the same directory.
 *
 */

namespace Facebook\AutoloadMap;

use Facebook\AutoloadMap\__Private\TypeAssert;

abstract final class ConfigurationLoader {

  public static function fromFile(string $path): Config {
    invariant(
      is_readable($path),
      'Tried to load configuration file %s, but it is not readable.',
      $path,
    );
    return self::fromJSON(file_get_contents($path), $path);
  }

  public static function fromJSON(string $json, string $path): Config {
    $decoded = json_decode($json, /* as array = */ true);
    invariant(
      is_array($decoded),
      'Expected configuration file to contain a JSON object, got %s',
      gettype($decoded),
    );
    return self::fromData(
      /* HH_IGNORE_ERROR[4110] */ $decoded,
      $path,
    );
  }

  public static function fromData(
    array<string, mixed> $data,
    string $path,
  ): Config {
    $failure_handler = TypeAssert\is_nullable_string(
      $data['failureHandler'] ?? null,
      'failureHandler',
    );

    return shape(
      'roots' => new ImmVector(
        TypeAssert\is_array_of_strings(
          $data['roots'] ?? null,
          'roots',
        ),
      ),
      'devRoots' => new ImmVector(
        TypeAssert\is_nullable_array_of_strings(
          $data['devRoots'] ?? null,
          'devRoots',
        ) ?? [],
      ),
      'autoloadFilesBehavior' => TypeAssert\is_nullable_enum(
        AutoloadFilesBehavior::class,
        $data['autoloadFilesBehavior'] ?? null,
        'autoloadFilesbehavior',
      ) ?? AutoloadFilesBehavior::FIND_DEFINITIONS,
      'relativeAutoloadRoot' => TypeAssert\is_nullable_bool(
        $data['relativeAutoloadRoot'] ?? null,
        'relativerAutoloadRoot',
      ) ?? true,
      'includeVendor' => TypeAssert\is_nullable_bool(
        $data['includeVendor'] ?? null,
        'includeVendor',
      ) ?? true,
      'extraFiles' => new ImmVector(
        TypeAssert\is_nullable_array_of_strings(
          $data['extraFiles'] ?? null,
          'extraFiles',
        ) ?? [],
      ),
      'parser' => TypeAssert\is_nullable_enum(
        Parser::class,
        $data['parser'] ?? null,
        'parser',
      ) ?? self::getDefaultParser(),
      'failureHandler' => $failure_handler,
      'devFailureHandler' => TypeAssert\is_nullable_string(
        $data['devFailureHandler'] ?? null,
        'devFailureHandler',
      ) ?? $failure_handler,
    );
  }

  private static function getDefaultParser(): Parser {
    if (extension_loaded('factparse')) {
      return Parser::EXT_FACTPARSE;
    }
    return Parser::DEFINITION_FINDER;
  }
}
