<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Chrometoaster\CodingStandard\Fixer\ClassNotation;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\ConfigurationDefinitionFixerInterface;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerConfiguration\AllowedValueSubset;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;
use SplFileInfo;

/**
 * Make sure there is one blank line above and below class elements.
 *
 * The exception is when an element is the first or last item in a 'classy'.
 *
 * @author SpacePossum
 */
final class ConfigurableClassAttributesSeparationFixer extends AbstractFixer implements ConfigurationDefinitionFixerInterface, WhitespacesAwareFixerInterface
{
    /**
     * @var array<string, true>
     */
    private $classElementTypes = [];

    /**
     * How many lines between class methods
     *
     * @var int
     */
    private $methodsSeparationLines = 1;

    /**
     * How many lines between constants, properties and methods in interfaces
     *
     * @var int
     */
    private $elementsSeparationLines = 1;


    /**
     * {@inheritdoc}
     */
    public function configure(array $configuration = null)
    {
        parent::configure($configuration);

        $this->classElementTypes = []; // reset previous configuration
        foreach ($this->configuration['elements'] as $element) {
            $this->classElementTypes[$element] = true;
        }

        $this->methodsSeparationLines  = $this->configuration['methodsSeparationLines'];
        $this->elementsSeparationLines = $this->configuration['elementsSeparationLines'];
    }


    /**
     * {@inheritdoc}
     */
    public function getDefinition()
    {
        return new FixerDefinition(
            'Class, trait and interface elements must be separated with one blank line.',
            [
                new CodeSample(
                    '<?php
final class Sample
{
    protected function foo()
    {
    }
    protected function bar()
    {
    }


}
'
                ),
                new CodeSample(
                    '<?php
class Sample
{private $a; // a is awesome
    /** second in a hour */
    private $b;
}
',
                    ['elements' => ['property']]
                ),
                new CodeSample(
                    '<?php
class Sample
{
    const A = 1;
    /** seconds in some hours */
    const B = 3600;
}
',
                    ['elements' => ['const']]
                ),
            ]
        );
    }


    /**
     * {@inheritdoc}
     */
    public function getPriority()
    {
        // Must run before BracesFixer and IndentationTypeFixer fixers because this fixer
        // might add line breaks to the code without indenting.
        return 55;
    }


    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return $tokens->isAnyTokenKindsFound(Token::getClassyTokenKinds());
    }


    /**
     * {@inheritdoc}
     */
    protected function applyFix(SplFileInfo $file, Tokens $tokens)
    {
        $tokensAnalyzer = new TokensAnalyzer($tokens);
        $class          = $classStart          = $classEnd          = false;

        foreach (array_reverse($tokensAnalyzer->getClassyElements(), true) as $index => $element) {
            if (!isset($this->classElementTypes[$element['type']])) {
                continue; // not configured to be fixed
            }

            if ($element['classIndex'] !== $class) {
                $class      = $element['classIndex'];
                $classStart = $tokens->getNextTokenOfKind($class, ['{']);
                $classEnd   = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $classStart);
            }

            if ('method' === $element['type'] && !$tokens[$class]->isGivenKind(T_INTERFACE)) {
                // method of class or trait
                $attributes = $tokensAnalyzer->getMethodAttributes($index);

                $methodEnd = true === $attributes['abstract']
                    ? $tokens->getNextTokenOfKind($index, [';'])
                    : $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $tokens->getNextTokenOfKind($index, ['{']));

                $this->fixSpaceBelowClassMethod($tokens, $classEnd, $methodEnd);
                $this->fixSpaceAboveClassMethod($tokens, $classStart, $index);

                continue;
            }

            // `const`, `property` or `method` of an `interface`
            $this->fixSpaceBelowClassElement($tokens, $classEnd, $tokens->getNextTokenOfKind($index, [';']));
            $this->fixSpaceAboveClassElement($tokens, $classStart, $index);
        }
    }


    /**
     * {@inheritdoc}
     */
    protected function createConfigurationDefinition()
    {
        $types = ['const', 'method', 'property'];

        return new FixerConfigurationResolver([
            (new FixerOptionBuilder('elements', sprintf('List of classy elements; \'%s\'.', implode("', '", $types))))
                ->setAllowedTypes(['array'])
                ->setAllowedValues([new AllowedValueSubset($types)])
                ->setDefault(['const', 'method', 'property'])
                ->getOption(),
            (new FixerOptionBuilder('methodsSeparationLines', 'Number of blank lines separating class methods'))
                ->setAllowedTypes(['int', 'string'])
                ->setDefault(1)
                ->getOption(),
            (new FixerOptionBuilder('elementsSeparationLines', 'Number of blank lines separating other elements (const, properties, interface methods)'))
                ->setAllowedTypes(['int', 'string'])
                ->setDefault(1)
                ->getOption(),
        ]);
    }


    /**
     * Fix spacing below an element of a class, interface or trait.
     *
     * Deals with comments, PHPDocs and spaces above the element with respect to the position of the
     * element within the class, interface or trait.
     *
     * @param Tokens $tokens
     * @param int    $classEndIndex
     * @param int    $elementEndIndex
     */
    private function fixSpaceBelowClassElement(Tokens $tokens, $classEndIndex, $elementEndIndex)
    {
        for ($nextNotWhite = $elementEndIndex + 1; ; ++$nextNotWhite) {
            if (($tokens[$nextNotWhite]->isComment() || $tokens[$nextNotWhite]->isWhitespace()) && false === mb_strpos($tokens[$nextNotWhite]->getContent(), "\n")) {
                continue;
            }

            break;
        }

        if ($tokens[$nextNotWhite]->isWhitespace()) {
            $nextNotWhite = $tokens->getNextNonWhitespace($nextNotWhite);
        }

        // if we're at the end
        if ($nextNotWhite === $classEndIndex) {
            $reqLines = 1;
        } else {
            $reqLines = 1 + $this->elementsSeparationLines;

            static $methodTokens = [T_PRIVATE, T_PROTECTED, T_PUBLIC, T_ABSTRACT, T_FINAL, T_STATIC, T_FUNCTION];

            // find out if next statement is a method
            $nextElementIndex = $nextNotWhite;
            for ($i = $nextNotWhite; $i < $classEndIndex; ++$i) {
                $nonWhiteBelow = $tokens->getNonWhitespaceSibling($i, 1);
                if (null !== $nonWhiteBelow && $tokens[$nonWhiteBelow]->isGivenKind($methodTokens)) {
                    $nextElementIndex = $nonWhiteBelow;
                } else {
                    break;
                }
            }

            // next is a method
            if ($tokens[$nextElementIndex]->isGivenKind(T_FUNCTION)) {

                // method separation is bigger than elements separation
                if ($this->methodsSeparationLines > $this->elementsSeparationLines) {
                    $reqLines = 1 + $this->methodsSeparationLines;
                }
            }
        }

        $this->correctLineBreaks($tokens, $elementEndIndex, $nextNotWhite, $reqLines, true);
    }


    /**
     * Fix spacing below a method of a class or trait.
     *
     * Deals with comments, PHPDocs and spaces above the method with respect to the position of the
     * method within the class or trait.
     *
     * @param Tokens $tokens
     * @param int    $classEndIndex
     * @param int    $elementEndIndex
     */
    private function fixSpaceBelowClassMethod(Tokens $tokens, $classEndIndex, $elementEndIndex)
    {
        $nextNotWhite = $tokens->getNextNonWhitespace($elementEndIndex);

        $this->correctLineBreaks($tokens, $elementEndIndex, $nextNotWhite, $nextNotWhite === $classEndIndex ? 1 : 1 + $this->methodsSeparationLines);
    }


    private function fixSpaceAboveClassMethod(Tokens $tokens, $classStartIndex, $elementIndex)
    {
        $this->fixSpaceAbove($tokens, $classStartIndex, $elementIndex, $this->methodsSeparationLines);
    }


    private function fixSpaceAboveClassElement(Tokens $tokens, $classStartIndex, $elementIndex)
    {
        $this->fixSpaceAbove($tokens, $classStartIndex, $elementIndex, $this->elementsSeparationLines);
    }


    /**
     * Fix spacing above an element of a class, interface or trait.
     *
     * Deals with comments, PHPDocs and spaces above the element with respect to the position of the
     * element within the class, interface or trait.
     *
     * @param Tokens $tokens
     * @param int    $classStartIndex index of the class Token the element is in
     * @param int    $elementIndex    index of the element to fix
     * @param mixed $linesAbove
     */
    private function fixSpaceAbove(Tokens $tokens, $classStartIndex, $elementIndex, $linesAbove)
    {
        static $methodAttr = [T_PRIVATE, T_PROTECTED, T_PUBLIC, T_ABSTRACT, T_FINAL, T_STATIC];

        // find out where the element definition starts
        $firstElementAttributeIndex = $elementIndex;
        for ($i = $elementIndex; $i > $classStartIndex; --$i) {
            $nonWhiteAbove = $tokens->getNonWhitespaceSibling($i, -1);
            if (null !== $nonWhiteAbove && $tokens[$nonWhiteAbove]->isGivenKind($methodAttr)) {
                $firstElementAttributeIndex = $nonWhiteAbove;
            } else {
                break;
            }
        }

        // deal with comments above a element
        if ($tokens[$nonWhiteAbove]->isGivenKind(T_COMMENT)) {
            if (1 === $firstElementAttributeIndex - $nonWhiteAbove) {
                // no white space found between comment and element start
                $this->correctLineBreaks($tokens, $nonWhiteAbove, $firstElementAttributeIndex, 1);

                return;
            }

            // $tokens[$nonWhiteAbove+1] is always a white space token here
            if (mb_substr_count($tokens[$nonWhiteAbove + 1]->getContent(), "\n") > 1) {
                // more than one line break, always bring it back to 2 line breaks between the element start and what is above it
                $this->correctLineBreaks($tokens, $nonWhiteAbove, $firstElementAttributeIndex, 2);

                return;
            }

            // there are 2 cases:
            if ($tokens[$nonWhiteAbove - 1]->isWhitespace() && mb_substr_count($tokens[$nonWhiteAbove - 1]->getContent(), "\n") > 0) {
                // 1. The comment is meant for the element (although not a PHPDoc),
                //    make sure there is one line break between the element and the comment...
                $this->correctLineBreaks($tokens, $nonWhiteAbove, $firstElementAttributeIndex, 1);
                //    ... and make sure there is blank line above the comment (with the exception when it is directly after a class opening)
                $nonWhiteAbove        = $this->findCommentBlockStart($tokens, $nonWhiteAbove);
                $nonWhiteAboveComment = $tokens->getNonWhitespaceSibling($nonWhiteAbove, -1);

                $this->correctLineBreaks($tokens, $nonWhiteAboveComment, $nonWhiteAbove, $nonWhiteAboveComment === $classStartIndex ? 1 : 1 + $linesAbove);
            } else {
                // 2. The comment belongs to the code above the element,
                //    make sure there is a blank line above the element (i.e. 2 line breaks)
                $this->correctLineBreaks($tokens, $nonWhiteAbove, $firstElementAttributeIndex, 1 + $linesAbove);
            }

            return;
        }

        // deal with element without a PHPDoc above it
        if (false === $tokens[$nonWhiteAbove]->isGivenKind(T_DOC_COMMENT)) {
            $this->correctLineBreaks($tokens, $nonWhiteAbove, $firstElementAttributeIndex, $nonWhiteAbove === $classStartIndex ? 1 : 1 + $linesAbove);

            return;
        }

        // there should be one linebreak between the element and the PHPDoc above it
        $this->correctLineBreaks($tokens, $nonWhiteAbove, $firstElementAttributeIndex, 1);

        // there should be one blank line between the PHPDoc and whatever is above (with the exception when it is directly after a class opening)
        $nonWhiteAbovePHPDoc = $tokens->getNonWhitespaceSibling($nonWhiteAbove, -1);
        $this->correctLineBreaks($tokens, $nonWhiteAbovePHPDoc, $nonWhiteAbove, $nonWhiteAbovePHPDoc === $classStartIndex ? 1 : 1 + $linesAbove);
    }


    /**
     * @param Tokens $tokens
     * @param int    $startIndex
     * @param int    $endIndex
     * @param int    $reqLineCount
     * @param mixed $expandOnly
     */
    private function correctLineBreaks(Tokens $tokens, $startIndex, $endIndex, $reqLineCount = 2, $expandOnly = false)
    {
        $lineEnding = $this->whitespacesConfig->getLineEnding();

        ++$startIndex;
        $numbOfWhiteTokens = $endIndex - $startIndex;
        if (0 === $numbOfWhiteTokens) {
            $tokens->insertAt($startIndex, new Token([T_WHITESPACE, str_repeat($lineEnding, $reqLineCount)]));

            return;
        }

        $lineBreakCount = $this->getLineBreakCount($tokens, $startIndex, $endIndex);
        if ($reqLineCount === $lineBreakCount) {
            return;
        }

        if ($lineBreakCount < $reqLineCount) {
            $tokens[$startIndex] = new Token([
                T_WHITESPACE,
                str_repeat($lineEnding, $reqLineCount - $lineBreakCount) . $tokens[$startIndex]->getContent(),
            ]);

            return;
        }

        // $lineCount = > $reqLineCount : check the one Token case first since this one will be true most of the time
        if (!$expandOnly && (1 === $numbOfWhiteTokens)) {
            $tokens[$startIndex] = new Token([
                T_WHITESPACE,
                Preg::replace('/\r\n|\n/', '', $tokens[$startIndex]->getContent(), $lineBreakCount - $reqLineCount),
            ]);

            return;
        }

        // $numbOfWhiteTokens = > 1
        $toReplaceCount = $lineBreakCount - $reqLineCount;
        for ($i = $startIndex; $i < $endIndex && $toReplaceCount > 0; ++$i) {
            $tokenLineCount = mb_substr_count($tokens[$i]->getContent(), "\n");
            if ($tokenLineCount > 0) {
                $tokens[$i] = new Token([
                    T_WHITESPACE,
                    Preg::replace('/\r\n|\n/', '', $tokens[$i]->getContent(), min($toReplaceCount, $tokenLineCount)),
                ]);
                $toReplaceCount -= $tokenLineCount;
            }
        }
    }


    /**
     * @param Tokens $tokens
     * @param int    $whiteSpaceStartIndex
     * @param int    $whiteSpaceEndIndex
     *
     * @return int
     */
    private function getLineBreakCount(Tokens $tokens, $whiteSpaceStartIndex, $whiteSpaceEndIndex)
    {
        $lineCount = 0;
        for ($i = $whiteSpaceStartIndex; $i < $whiteSpaceEndIndex; ++$i) {
            $lineCount += mb_substr_count($tokens[$i]->getContent(), "\n");
        }

        return $lineCount;
    }


    /**
     * @param Tokens $tokens
     * @param int    $commentIndex
     *
     * @return int
     */
    private function findCommentBlockStart(Tokens $tokens, $commentIndex)
    {
        $start = $commentIndex;
        for ($i = $commentIndex - 1; $i > 0; --$i) {
            if ($tokens[$i]->isComment()) {
                $start = $i;

                continue;
            }

            if (!$tokens[$i]->isWhitespace() || $this->getLineBreakCount($tokens, $i, $i + 1) > 1) {
                break;
            }
        }

        return $start;
    }
}
