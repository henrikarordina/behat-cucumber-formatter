<?php

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;

/**
 * Defines application features from the specific context.
 */
class FeatureContext implements Context
{
    /** @var string */
    private $phpBin;

    /** @var \Symfony\Component\Process\Process */
    private $process;

    /** @var string */
    private $workingDir;

    /**
     * Cleans test folders in the temporary directory.
     *
     * @BeforeSuite
     * @AfterSuite
     */
    public static function cleanTestFolders()
    {
        if (is_dir($dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'behat')) {
            self::clearDirectory($dir);
        }
    }

    /**
     * Clears a complete directory by path.
     *
     * @param string $path
     */
    private static function clearDirectory($path)
    {
        $files = scandir($path);
        array_shift($files);
        array_shift($files);
        foreach ($files as $file) {
            $file = $path . DIRECTORY_SEPARATOR . $file;
            if (is_dir($file)) {
                self::clearDirectory($file);
            } else {
                unlink($file);
            }
        }
        rmdir($path);
    }

    /**
     * Prepares test folders in the temporary directory.
     *
     * @BeforeScenario
     */
    public function prepareTestFolders()
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'behat' . DIRECTORY_SEPARATOR .
            md5(microtime() . rand(0, 10000));
        $reportsDir = $dir . DIRECTORY_SEPARATOR . 'reports';
        // create directories
        mkdir(
            sprintf(
                '%1$s%2$sfeatures%2$sbootstrap%2$si18n',
                $dir,
                DIRECTORY_SEPARATOR
            ),
            0777,
            true
        );
        mkdir($dir . DIRECTORY_SEPARATOR . 'junit');
        mkdir($reportsDir);

        // create configuration
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'behat.yml', <<<EOF
default:
    suites:
        default:
            paths:
                - "$dir"
            contexts:
                - ExampleFeatureContext
    extensions:
        Vanare\BehatCucumberJsonFormatter\Extension:
            fileNamePrefix: report
            outputDir: "$reportsDir"
EOF
        );

        // copy context
        copy(
            sprintf(
                'features%1$sbootstrap%1$sExampleFeatureContext.php',
                DIRECTORY_SEPARATOR
            ),
            sprintf(
                '%1$s%2$sfeatures%2$sbootstrap%2$sExampleFeatureContext.php',
                $dir,
                DIRECTORY_SEPARATOR
            )
        );

        // setup variables
        $phpFinder = new \Symfony\Component\Process\PhpExecutableFinder();
        if (false === $php = $phpFinder->find()) {
            throw new \RuntimeException('Unable to find the PHP executable.');
        }
        $this->workingDir = $dir;
        $this->phpBin = $php;
        $this->process = new Symfony\Component\Process\Process(null);
        $this->process->setTimeout(20);
    }

    /**
     * @Given I have the following feature:
     * @param PyStringNode $string
     */
    public function iHaveTheFollowingFeature(PyStringNode $string)
    {
        file_put_contents($this->workingDir . '/features/feature.feature', $string->getRaw());
    }

    /**
     * @When I run behat with the converter
     */
    public function iRunBehatWithTheConverter()
    {
        $this->process->setWorkingDirectory($this->workingDir);
        $this->process->setCommandLine(
            sprintf(
                '%s %s -c %s -s default --no-interaction -f cucumber_json',
                $this->phpBin,
                escapeshellarg(BEHAT_BIN_PATH),
                $this->workingDir . DIRECTORY_SEPARATOR . 'behat.yml'
            )
        );
        // Don't reset the LANG variable on HHVM, because it breaks HHVM itself
        if (!defined('HHVM_VERSION')) {
            $env = $this->process->getEnv();
            $env['LANG'] = 'en'; // Ensures that the default language is en, whatever the OS locale is.
            $this->process->setEnv($env);
        }
        $this->process->run();
    }

    /**
     * @Then the result file will be:
     * @param PyStringNode $string
     */
    public function theResultFileWillBe(PyStringNode $string)
    {
        $reportFiles = glob(
            sprintf(
                '%1$s%2$sreports%2$sreport*.json',
                $this->workingDir,
                DIRECTORY_SEPARATOR
            )
        );

        $expected = json_decode($string->getRaw(), true);
        $actual = json_decode(file_get_contents(sprintf($reportFiles[0])), true);

        PHPUnit_Framework_Assert::assertEquals(
            self::removeDynamics($expected),
            self::removeDynamics($actual)
        );
    }

    /**
     * Removes the dynamic parts of a result, like the feature path and durations.
     *
     * @param array $array
     * @return array
     */
    private static function removeDynamics(array $array)
    {
        foreach ($array as &$feature) {
            $feature['uri'] = 'features/features.feature';
            foreach ($feature['elements'] as &$scenario) {
                foreach ($scenario['steps'] as &$step) {
                    $step['result']['duration'] = 12345;
                }
            }
        }
        return $array;
    }
}
