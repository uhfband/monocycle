<?php

namespace Uhf\Monocycle\Proto\WebSocket\Server;

use Exception;

/**
 * A frame that breaks RFC 6455 (or uses an unsupported feature). The code is
 * the close status to send to the client (1002 protocol error, 1009 too big).
 */
class ProtocolException extends Exception
{
    public const CLOSE_PROTOCOL_ERROR = 1002;
    public const CLOSE_MESSAGE_TOO_BIG = 1009;
}
