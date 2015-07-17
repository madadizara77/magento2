<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Composer;

use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Tests Magento\Framework\ComposerInformation
 */
class ComposerInformationTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|\Magento\Framework\App\Filesystem\DirectoryList
     */
    private $directoryList;

    /**
     * @var ComposerJsonFinder
     */
    private $composerJsonFinder;    
    
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var BufferIoFactory|\PHPUnit_Framework_MockObject_MockObject
     */
    private $bufferIoFactoryMock;

    public function setUp()
    {
        $this->directoryList = $this->getMock('Magento\Framework\App\Filesystem\DirectoryList', [], [], '', false);
        $this->composerJsonFinder = new ComposerJsonFinder($this->directoryList);
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->directoryReadMock = $this->getMock('Magento\Framework\Filesystem\Directory\Read', [], [], '', false);
        $this->filesystemMock = $this->getMock('Magento\Framework\Filesystem', [], [], '', false);
        $this->filesystemMock
            ->expects($this->any())
            ->method('getDirectoryRead')
            ->will($this->returnValue($this->directoryReadMock));
        $this->ioMock = $this->getMock('Composer\IO\BufferIO', [], [], '', false);
        $this->bufferIoFactoryMock = $this->getMock('Magento\Framework\Composer\BufferIoFactory', [], [], '', false);
        $this->bufferIoFactoryMock->expects($this->any())->method('create')->willReturn($this->ioMock);
    }

    /**
     * Setup DirectoryReadMock to use a specified directory for reading composer files
     *
     * @param $composerDir string Directory under _files that contains composer files
     */
    private function setupDirectoryMock($composerDir)
    {
        $valueMap = [
            [DirectoryList::CONFIG, __DIR__ . '/_files/'],
            [DirectoryList::ROOT, __DIR__ . '/_files/' . $composerDir],
            [DirectoryList::COMPOSER_HOME, __DIR__ . '/_files/' . $composerDir],
        ];

        $this->directoryList->expects($this->any())
            ->method('getPath')
            ->will($this->returnValueMap($valueMap));
    }

    /**
     * @param $composerDir string Directory under _files that contains composer files
     *
     * @dataProvider getRequiredPhpVersionDataProvider
     */
    public function testGetRequiredPhpVersion($composerDir)
    {
        $this->setupDirectoryMock($composerDir);

        /** @var \Magento\Framework\Composer\ComposerInformation $composerInfo */
        $composerInfo = $this->objectManager->create(
            'Magento\Framework\Composer\ComposerInformation',
            [
                'applicationFactory' => new MagentoComposerApplicationFactory($this->composerJsonFinder, $this->directoryList),
                'filesystem' => $this->filesystemMock,
                'bufferIoFactory' => $this->bufferIoFactoryMock
             ]
        );

        $this->assertEquals("~5.5.0|~5.6.0", $composerInfo->getRequiredPhpVersion());
    }

    /**
     * @param $composerDir string Directory under _files that contains composer files
     *
     * @dataProvider getRequiredPhpVersionDataProvider
     */
    public function testGetRequiredExtensions($composerDir)
    {
        $this->setupDirectoryMock($composerDir);
        $expectedExtensions = ['ctype', 'gd', 'spl', 'dom', 'simplexml', 'mcrypt', 'hash', 'curl', 'iconv', 'intl'];

        /** @var \Magento\Framework\Composer\ComposerInformation $composerInfo */
        $composerInfo = $this->objectManager->create(
            'Magento\Framework\Composer\ComposerInformation',
            [
                'applicationFactory' => new MagentoComposerApplicationFactory($this->composerJsonFinder, $this->directoryList),
                'filesystem' => $this->filesystemMock,
                'bufferIoFactory' => $this->bufferIoFactoryMock
            ]
        );

        $actualRequiredExtensions = $composerInfo->getRequiredExtensions();
        foreach ($expectedExtensions as $expectedExtension) {
            $this->assertContains($expectedExtension, $actualRequiredExtensions);
        }
    }

    /**
     * @param $composerDir string Directory under _files that contains composer files
     *
     * @dataProvider getRequiredPhpVersionDataProvider
     */
    public function testGetRootRequiredPackagesAndTypes($composerDir)
    {
        $this->setupDirectoryMock($composerDir);

        /** @var \Magento\Framework\Composer\ComposerInformation $composerInfo */
        $composerInfo = $this->objectManager->create(
            'Magento\Framework\Composer\ComposerInformation',
            [
                'applicationFactory' => new MagentoComposerApplicationFactory($this->composerJsonFinder, $this->directoryList),
                'filesystem' => $this->filesystemMock,
                'bufferIoFactory' => $this->bufferIoFactoryMock
            ]
        );

        $requiredPackagesAndTypes = $composerInfo->getRootRequiredPackageTypesByName();

        $this->assertArrayHasKey('composer/composer', $requiredPackagesAndTypes);
        $this->assertEquals('library', $requiredPackagesAndTypes['composer/composer']);
    }

    public function testGetPackagesForUpdate()
    {
        $packageName = 'composer/composer';

        $this->setupDirectoryMock('testSkeleton');

        /** @var \Magento\Framework\Composer\ComposerInformation $composerInfo */
        $composerInfo = $this->objectManager->create(
            'Magento\Framework\Composer\ComposerInformation',
            [
                'applicationFactory' => new MagentoComposerApplicationFactory($this->directoryList)
            ]
        );

        $requiredPackages = $composerInfo->getRootRequiredPackageTypesByNameVersion();
        $this->assertArrayHasKey($packageName, $requiredPackages);

        $this->assertTrue($composerInfo->syncPackagesForUpdate());

        $packagesForUpdate = $composerInfo->getPackagesForUpdate();
        $this->assertArrayHasKey('packages', $packagesForUpdate);
        $this->assertArrayHasKey($packageName, $packagesForUpdate['packages']);
        $this->assertTrue(
            version_compare(
                $packagesForUpdate['packages'][$packageName]['latestVersion'],
                $requiredPackages[$packageName]['version'],
                '>'
            )
        );
    }

    /**
     * Data provider that returns directories containing different types of composer files.
     *
     * @return array
     */
    public function getRequiredPhpVersionDataProvider()
    {
        return [
            'Skeleton Composer' => ['testSkeleton'],
            'Composer.json from git clone' => ['testFromClone'],
            'Composer.json from git create project' => ['testFromCreateProject'],
        ];
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Composer file not found
     */
    public function testNoLock()
    {
        $this->setupDirectoryMock('notARealDirectory');
        $this->objectManager->create(
            'Magento\Framework\Composer\ComposerInformation',
            [
                'applicationFactory' => new MagentoComposerApplicationFactory($this->composerJsonFinder, $this->directoryList),
                'filesystem' => $this->filesystemMock,
                'bufferIoFactory' => $this->bufferIoFactoryMock
            ]
        );
    }
}
