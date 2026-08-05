<?php

namespace Zatca\DTOs;

/** Absolute paths for one isolated SDK invocation workspace. */
class SdkWorkspace
{
    public function __construct(
        public readonly string $root,
        public readonly string $appsDir,
        public readonly string $configurationDir,
        public readonly string $certificatesDir,
        public readonly string $inputDir,
        public readonly string $outputDir,
        public readonly string $pihPath,
    ) {}

    public function certificatePath(): string
    {
        return $this->certificatesDir.DIRECTORY_SEPARATOR.'cert.pem';
    }

    public function privateKeyPath(): string
    {
        return $this->certificatesDir.DIRECTORY_SEPARATOR.'ec-secp256k1-priv-key.pem';
    }
}
