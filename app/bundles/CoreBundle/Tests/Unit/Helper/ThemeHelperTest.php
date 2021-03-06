<?php

/*
 * @copyright   2018 Mautic Inc. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://www.mautic.com
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CoreBundle\Tests\Unit\Helper;

use Mautic\CoreBundle\Exception\FileNotFoundException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\Filesystem;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Helper\TemplatingHelper;
use Mautic\CoreBundle\Helper\ThemeHelper;
use Mautic\CoreBundle\Templating\TemplateNameParser;
use Mautic\CoreBundle\Templating\TemplateReference;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Templating\DelegatingEngine;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\TranslatorInterface;

class ThemeHelperTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var PathsHelper|MockObject
     */
    private $pathsHelper;

    /**
     * @var TemplatingHelper|MockObject
     */
    private $templatingHelper;

    /**
     * @var TranslatorInterface|MockObject
     */
    private $translator;

    /**
     * @var CoreParametersHelper|MockObject
     */
    private $coreParameterHelper;

    /**
     * @var ThemeHelper
     */
    private $themeHelper;

    protected function setUp(): void
    {
        $this->pathsHelper         = $this->createMock(PathsHelper::class);
        $this->templatingHelper    = $this->createMock(TemplatingHelper::class);
        $this->translator          = $this->createMock(TranslatorInterface::class);
        $this->coreParameterHelper = $this->createMock(CoreParametersHelper::class);
        $this->coreParameterHelper->method('get')
            ->with('theme_import_allowed_extensions')
            ->willReturn(['json', 'twig', 'css', 'js', 'htm', 'html', 'txt', 'jpg', 'jpeg', 'png', 'gif']);

        $this->themeHelper = new ThemeHelper(
            $this->pathsHelper,
            $this->templatingHelper,
            $this->translator,
            $this->coreParameterHelper,
            new Filesystem(),
            new Finder()
        );
    }

    public function testExceptionThrownWithMissingConfig()
    {
        $this->expectException(FileNotFoundException::class);

        $this->pathsHelper->method('getSystemPath')
            ->with('themes', true)
            ->willReturn(__DIR__.'/resource/themes');

        $this->translator->expects($this->once())
            ->method('trans')
            ->with('mautic.core.theme.missing.files', $this->anything(), 'validators')
            ->willReturnCallback(
                function ($key, array $parameters) {
                    $this->assertStringContainsString('config.json', $parameters['%files%']);
                }
            );

        $this->themeHelper->install(__DIR__.'/resource/themes/missing-config.zip');
    }

    public function testExceptionThrownWithMissingMessage()
    {
        $this->expectException(FileNotFoundException::class);

        $this->pathsHelper->method('getSystemPath')
            ->with('themes', true)
            ->willReturn(__DIR__.'/resource/themes');

        $this->translator->expects($this->once())
            ->method('trans')
            ->with('mautic.core.theme.missing.files', $this->anything(), 'validators')
            ->willReturnCallback(
                function ($key, array $parameters) {
                    $this->assertStringContainsString('message.html.twig', $parameters['%files%']);
                }
            );

        $this->themeHelper->install(__DIR__.'/resource/themes/missing-message.zip');
    }

    public function testExceptionThrownWithMissingFeature()
    {
        $this->expectException(FileNotFoundException::class);

        $this->pathsHelper->method('getSystemPath')
            ->with('themes', true)
            ->willReturn(__DIR__.'/resource/themes');

        $this->translator->expects($this->once())
            ->method('trans')
            ->with('mautic.core.theme.missing.files', $this->anything(), 'validators')
            ->willReturnCallback(
                function ($key, array $parameters) {
                    $this->assertStringContainsString('page.html.twig', $parameters['%files%']);
                }
            );

        $this->themeHelper->install(__DIR__.'/resource/themes/missing-feature.zip');
    }

    public function testThemeIsInstalled()
    {
        $fs = new Filesystem();
        $fs->copy(__DIR__.'/resource/themes/good.zip', __DIR__.'/resource/themes/good-tmp.zip');

        $this->pathsHelper->method('getSystemPath')
            ->with('themes', true)
            ->willReturn(__DIR__.'/resource/themes');

        $this->themeHelper->install(__DIR__.'/resource/themes/good-tmp.zip');

        $this->assertFileExists(__DIR__.'/resource/themes/good-tmp');

        $fs->remove(__DIR__.'/resource/themes/good-tmp');
    }

    public function testThemeFallbackToDefaultIfTemplateIsMissing()
    {
        $templateNameParser = $this->createMock(TemplateNameParser::class);
        $this->templatingHelper->expects($this->once())
            ->method('getTemplateNameParser')
            ->willReturn($templateNameParser);
        $templateNameParser->expects($this->once())
            ->method('parse')
            ->willReturn(
                new TemplateReference('', 'goldstar', 'page', 'html')
            );

        $templating = $this->createMock(DelegatingEngine::class);

        // twig does not exist
        $templating->expects($this->at(0))
            ->method('exists')
            ->willReturn(false);

        // php does not exist
        $templating->expects($this->at(1))
            ->method('exists')
            ->willReturn(false);

        // default themes twig exists
        $templating->expects($this->at(2))
            ->method('exists')
            ->willReturn(true);

        $this->templatingHelper->expects($this->once())
            ->method('getTemplating')
            ->willReturn($templating);

        $this->pathsHelper->method('getSystemPath')
            ->willReturnCallback(
                function ($path, $absolute) {
                    switch ($path) {
                        case 'themes':
                            return ($absolute) ? __DIR__.'/../../../../../../resource/themes' : 'themes';
                        case 'themes_root':
                            return __DIR__.'/../../../../../..';
                    }
                }
            );

        $this->themeHelper->setDefaultTheme('nature');

        $template = $this->themeHelper->checkForTwigTemplate(':goldstar:page.html.twig');
        $this->assertEquals(':nature:page.html.twig', $template);
    }

    public function testThemeFallbackToNextBestIfTemplateIsMissingForBothRequestedAndDefaultThemes()
    {
        $templateNameParser = $this->createMock(TemplateNameParser::class);
        $this->templatingHelper->expects($this->once())
            ->method('getTemplateNameParser')
            ->willReturn($templateNameParser);
        $templateNameParser->expects($this->once())
            ->method('parse')
            ->willReturn(
                new TemplateReference('', 'goldstar', 'page', 'html')
            );

        $templating = $this->createMock(DelegatingEngine::class);

        // twig does not exist
        $templating->expects($this->at(0))
            ->method('exists')
            ->willReturn(false);

        // php does not exist
        $templating->expects($this->at(1))
            ->method('exists')
            ->willReturn(false);

        // default theme twig does not exist
        $templating->expects($this->at(2))
            ->method('exists')
            ->willReturn(false);

        // next theme exists
        $templating->expects($this->at(3))
            ->method('exists')
            ->willReturn(true);

        $this->templatingHelper->expects($this->once())
            ->method('getTemplating')
            ->willReturn($templating);

        $this->pathsHelper->method('getSystemPath')
            ->willReturnCallback(
                function ($path, $absolute) {
                    switch ($path) {
                        case 'themes':
                            return ($absolute) ? __DIR__.'/../../../../../../themes' : 'themes';
                        case 'themes_root':
                            return __DIR__.'/../../../../../..';
                    }
                }
            );

        $this->themeHelper->setDefaultTheme('nature');

        $template = $this->themeHelper->checkForTwigTemplate(':goldstar:page.html.twig');
        $this->assertNotEquals(':nature:page.html.twig', $template);
        $this->assertNotEquals(':goldstar:page.html.twig', $template);
        $this->assertStringContainsString(':page.html.twig', $template);
    }

    public function testCopyWithNoNewDirName(): void
    {
        $themeHelper = new ThemeHelper(
            new class() extends PathsHelper {
                public function __construct()
                {
                }

                public function getSystemPath($name, $fullPath = false)
                {
                    Assert::assertSame('themes', $name);

                    return '/path/to/themes';
                }
            },
            new class() extends TemplatingHelper {
                public function __construct()
                {
                }
            },
            new class() extends Translator {
                public function __construct()
                {
                }
            },
            new class() extends CoreParametersHelper {
                public function __construct()
                {
                }
            },
            new class() extends Filesystem {
                public function __construct()
                {
                }

                public function exists($files)
                {
                    if ('/path/to/themes/new-theme-name' === $files) {
                        return false;
                    }

                    return true;
                }

                public function mirror($originDir, $targetDir, ?\Traversable $iterator = null, $options = [])
                {
                    Assert::assertSame('/path/to/themes/origin-template-dir', $originDir);
                    Assert::assertSame('/path/to/themes/new-theme-name', $targetDir);
                }

                public function readFile(string $filename): string
                {
                    Assert::assertStringEndsWith('/config.json', $filename);

                    return '{"name":"Origin Theme"}';
                }

                public function dumpFile($filename, $content)
                {
                    Assert::assertSame('/path/to/themes/new-theme-name/config.json', $filename);
                    Assert::assertSame('{"name":"New Theme Name"}', $content);
                }
            },
            new class() extends Finder {
                private $dirs = [];

                public function __construct()
                {
                }

                public function in($dirs)
                {
                    $this->dirs = [
                        new \SplFileInfo('origin-template-dir'),
                    ];

                    return $this;
                }

                public function getIterator()
                {
                    return new \ArrayIterator($this->dirs);
                }
            }
        );

        $themeHelper->copy('origin-template-dir', 'New Theme Name');
    }

    public function testCopyWithNewDirName(): void
    {
        $themeHelper = new ThemeHelper(
            new class() extends PathsHelper {
                public function __construct()
                {
                }

                public function getSystemPath($name, $fullPath = false)
                {
                    Assert::assertSame('themes', $name);

                    return '/path/to/themes';
                }
            },
            new class() extends TemplatingHelper {
                public function __construct()
                {
                }
            },
            new class() extends Translator {
                public function __construct()
                {
                }
            },
            new class() extends CoreParametersHelper {
                public function __construct()
                {
                }
            },
            new class() extends Filesystem {
                public function __construct()
                {
                }

                public function exists($files)
                {
                    if ('/path/to/themes/requested-theme-dir' === $files) {
                        return false;
                    }

                    return true;
                }

                public function mirror($originDir, $targetDir, ?\Traversable $iterator = null, $options = [])
                {
                    Assert::assertSame('/path/to/themes/origin-template-dir', $originDir);
                    Assert::assertSame('/path/to/themes/requested-theme-dir', $targetDir);
                }

                public function readFile(string $filename): string
                {
                    Assert::assertStringEndsWith('/config.json', $filename);

                    return '{"name":"Origin Theme"}';
                }

                public function dumpFile($filename, $content)
                {
                    Assert::assertSame('/path/to/themes/requested-theme-dir/config.json', $filename);
                    Assert::assertSame('{"name":"New Theme Name"}', $content);
                }
            },
            new class() extends Finder {
                private $dirs = [];

                public function __construct()
                {
                }

                public function in($dirs)
                {
                    $this->dirs = [
                        new \SplFileInfo('origin-template-dir'),
                    ];

                    return $this;
                }

                public function getIterator()
                {
                    return new \ArrayIterator($this->dirs);
                }
            }
        );

        $themeHelper->copy('origin-template-dir', 'New Theme Name', 'requested-theme-dir');
    }
}
