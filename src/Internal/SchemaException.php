<?php

declare(strict_types=1);

namespace Tmailor\Internal;

/**
 * A response body does not match the schema. The message names the field and the expected type, never the value.
 *
 * @internal
 */
final class SchemaException extends \UnexpectedValueException
{
}
