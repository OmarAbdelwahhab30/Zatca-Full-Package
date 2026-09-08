<?php

namespace Zatca\Tests\Unit;

use Zatca\Services\SDK\ZatcaSdkRuntimeManager;
use Zatca\Tests\TestCase;

/**
 * Proves two concurrent SDK operations get fully isolated workspaces —
 * different root directories, different Certificate/private-key/XML/output
 * file paths — and that writing credentials into one workspace or
 * destroying it never touches the other. This is what makes it safe to
 * sign/validate multiple invoices at the same time.
 */
class ZatcaSdkRuntimeManagerIsolationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $sdkRoot = sys_get_temp_dir().'/zatca-fake-sdk-'.uniqid();
        mkdir($sdkRoot.'/Data/Schemas/xsds/UBL2.1/xsd/maindoc', 0700, true);
        mkdir($sdkRoot.'/Data/Rules/Schematrons', 0700, true);
        mkdir($sdkRoot.'/Configuration', 0700, true);
        touch($sdkRoot.'/Data/Schemas/xsds/UBL2.1/xsd/maindoc/UBL-Invoice-2.1.xsd');
        touch($sdkRoot.'/Data/Rules/Schematrons/CEN-EN16931-UBL.xsl');
        touch($sdkRoot.'/Data/Rules/Schematrons/20210819_ZATCA_E-invoice_Validation_Rules.xsl');
        touch($sdkRoot.'/Configuration/usage.txt');

        $jarPath = $sdkRoot.'/Apps/fake.jar';
        mkdir(dirname($jarPath), 0700, true);
        touch($jarPath);

        $app['config']->set('zatca.sdk.root', $sdkRoot);
        $app['config']->set('zatca.sdk.executable', $jarPath);
    }

    public function test_two_concurrent_workspaces_never_share_any_path(): void
    {
        $runtime = app(ZatcaSdkRuntimeManager::class);

        $workspaceA = $runtime->create();
        $workspaceB = $runtime->create();

        $this->assertNotSame($workspaceA->root, $workspaceB->root);
        $this->assertNotSame($workspaceA->certificatePath(), $workspaceB->certificatePath());
        $this->assertNotSame($workspaceA->privateKeyPath(), $workspaceB->privateKeyPath());
        $this->assertNotSame($workspaceA->outputDir, $workspaceB->outputDir);
        $this->assertNotSame($workspaceA->pihPath, $workspaceB->pihPath);
        $this->assertStringNotContainsString($workspaceB->root, $workspaceA->root);

        $runtime->destroy($workspaceA);
        $runtime->destroy($workspaceB);
    }

    public function test_workspace_config_points_at_workspace_credentials(): void
    {
        $runtime = app(ZatcaSdkRuntimeManager::class);

        $workspace = $runtime->create();

        $config = json_decode(file_get_contents($workspace->configurationDir.DIRECTORY_SEPARATOR.'config.json'), true);

        $this->assertSame($workspace->certificatePath(), $config['certPath']);
        $this->assertSame($workspace->privateKeyPath(), $config['privateKeyPath']);
        $this->assertSame($workspace->pihPath, $config['pihPath']);

        $runtime->destroy($workspace);
    }

    public function test_writing_credentials_into_one_workspace_never_touches_the_other(): void
    {
        $runtime = app(ZatcaSdkRuntimeManager::class);

        $workspaceA = $runtime->create();
        $workspaceB = $runtime->create();

        $runtime->writeCredentials($workspaceA, 'CERT-FOR-A', 'KEY-FOR-A');
        $runtime->writeCredentials($workspaceB, 'CERT-FOR-B', 'KEY-FOR-B');

        $this->assertSame('CERT-FOR-A', file_get_contents($workspaceA->certificatePath()));
        $this->assertSame('CERT-FOR-B', file_get_contents($workspaceB->certificatePath()));
        $this->assertNotEquals(file_get_contents($workspaceA->certificatePath()), file_get_contents($workspaceB->certificatePath()));

        $runtime->destroy($workspaceA);
        $runtime->destroy($workspaceB);
    }

    public function test_destroying_one_workspace_leaves_the_other_fully_intact(): void
    {
        $runtime = app(ZatcaSdkRuntimeManager::class);

        $workspaceA = $runtime->create();
        $workspaceB = $runtime->create();

        $runtime->writeCredentials($workspaceB, 'CERT-B', 'KEY-B');

        $runtime->destroy($workspaceA);

        $this->assertDirectoryDoesNotExist($workspaceA->root);
        $this->assertDirectoryExists($workspaceB->root);
        $this->assertSame('CERT-B', file_get_contents($workspaceB->certificatePath()));

        $runtime->destroy($workspaceB);
    }

    public function test_workspace_files_are_created_with_restrictive_permissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permission bits are not meaningful on Windows filesystems.');
        }

        $runtime = app(ZatcaSdkRuntimeManager::class);
        $workspace = $runtime->create();

        $runtime->writeCredentials($workspace, 'CERT', 'KEY');

        $this->assertSame('0600', substr(sprintf('%o', fileperms($workspace->certificatePath())), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($workspace->privateKeyPath())), -4));

        $runtime->destroy($workspace);
    }
}
