<?php

namespace Socks;

use React\Promise\Deferred;
use React\Stream\Stream;
use \InvalidArgumentException;
use \UnexpectedValueException;

class StreamReader
{
    public function readBinary(Stream $stream, $structure)
    {
        $length = 0;
        $unpack = '';
        foreach ($structure as $name=>$format) {
            if ($length !== 0) {
                $unpack .= '/';
            }
            $unpack .= $format . $name;

            if ($format === 'C') {
                ++$length;
            } else if ($format === 'n') {
                $length += 2;
            } else if ($format === 'N') {
                $length += 4;
            } else {
                throw new InvalidArgumentException('Invalid format given');
            }
        }

        return $this->readLength($stream, $length)->then(function ($response) use ($unpack) {
            return unpack($unpack, $response);
        });
    }

    public function readLength(Stream $stream, $bytes)
    {
        $deferred = new Deferred();
        $oldsize = $stream->bufferSize;
        $stream->bufferSize = $bytes;

        $buffer = '';

        $fn = function ($data, Stream $stream) use (&$buffer, &$bytes, $deferred, $oldsize, &$fn) {
            $bytes -= strlen($data);
            $buffer .= $data;

            $deferred->progress($data);

            if ($bytes === 0) {
                $stream->bufferSize = $oldsize;
                $stream->removeListener('data', $fn);

                $deferred->resolve($buffer);
            } else {
                $stream->bufferSize = $bytes;
            }
        };
        $stream->on('data', $fn);
        return $deferred->promise();
    }

    public function readAssert(Stream $stream, $byteSequence)
    {
        $deferred = new Deferred();
        $pos = 0;

        $that = $this;
        $this->readLength($stream, strlen($byteSequence))->then(function ($data) use ($deferred) {
            $deferred->resolve($data);
        }, null, function ($part) use ($byteSequence, &$pos, $deferred, $that){
            $len = strlen($part);
            $expect = substr($byteSequence, $pos, $len);

            if ($part === $expect) {
                $pos += $len;
            } else {
                $deferred->reject(new UnexpectedValueException('expected "'.$that->s($expect).'", but got "'.$that->s($part).'"'));
            }
        });
        return $deferred->promise();
    }

    public function s($bytes){
        $ret = '';
        for ($i = 0, $l = strlen($bytes); $i < $l; ++$i) {
            if ($i !== 0) {
                $ret .= ' ';
            }
            $ret .= sprintf('0x%02X', ord($bytes[$i]));
        }
        return $ret;
    }
}