<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Config\Builder;

use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BooleanNode;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\FloatNode;
use Symfony\Component\Config\Definition\IntegerNode;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\Config\Definition\ScalarNode;
use Symfony\Component\Config\Definition\VariableNode;
use Symfony\Component\Config\Loader\ParamConfigurator;

/**
 * Generate ConfigBuilders to help create valid config.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class ConfigBuilderGenerator implements ConfigBuilderGeneratorInterface
{
    private array $classes = [];
    private string $outputDir;

    public function __construct(string $outputDir)
    {
        $this->outputDir = $outputDir;
    }

    /**
     * @return \Closure that will return the root config class
     */
    public function build(ConfigurationInterface $configuration): \Closure
    {
        $this->classes = [];

        $rootNode = $configuration->getConfigTreeBuilder()->buildTree();
        $rootClass = new ClassBuilder('Symfony\\Config', $rootNode->getName());

        $path = $this->getFullPath($rootClass);
        if (!is_file($path)) {
            // Generate the class if the file not exists
            $this->classes[] = $rootClass;
            $this->buildNode($rootNode, $rootClass, $this->getSubNamespace($rootClass));
            $rootClass->addImplements(ConfigBuilderInterface::class);
            $rootClass->addMethod('getExtensionAlias', '
public function NAME(): string
{
    return \'ALIAS\';
}', ['ALIAS' => $rootNode->getPath()]);

            $this->writeClasses();
        }

        return function () use ($path, $rootClass) {
            require_once $path;
            $className = $rootClass->getFqcn();

            return new $className();
        };
    }

    private function getFullPath(ClassBuilder $class): string
    {
        $directory = $this->outputDir.\DIRECTORY_SEPARATOR.$class->getDirectory();
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        return $directory.\DIRECTORY_SEPARATOR.$class->getFilename();
    }

    private function writeClasses(): void
    {
        foreach ($this->classes as $class) {
            $this->buildConstructor($class);
            $this->buildToArray($class);
            $this->buildSetExtraKey($class);

            file_put_contents($this->getFullPath($class), $class->build());
        }

        $this->classes = [];
    }

    private function buildNode(NodeInterface $node, ClassBuilder $class, string $namespace): void
    {
        if (!$node instanceof ArrayNode) {
            throw new \LogicException('The node was expected to be an ArrayNode. This Configuration includes an edge case not supported yet.');
        }

        foreach ($node->getChildren() as $child) {
            switch (true) {
                case $child instanceof ScalarNode:
                    $this->handleScalarNode($child, $class);
                    break;
                case $child instanceof PrototypedArrayNode:
                    $this->handlePrototypedArrayNode($child, $class, $namespace);
                    break;
                case $child instanceof VariableNode:
                    $this->handleVariableNode($child, $class);
                    break;
                case $child instanceof ArrayNode:
                    $this->handleArrayNode($child, $class, $namespace);
                    break;
                default:
                    throw new \RuntimeException(sprintf('Unknown node "%s".', \get_class($child)));
            }
        }
    }

    private function handleArrayNode(ArrayNode $node, ClassBuilder $class, string $namespace): void
    {
        $childClass = new ClassBuilder($namespace, $node->getName());
        $childClass->setAllowExtraKeys($node->shouldIgnoreExtraKeys());
        $class->addRequire($childClass);
        $this->classes[] = $childClass;

        $property = $class->addProperty($node->getName(), $childClass->getFqcn());
        $body = '
public function NAME(array $value = []): CLASS
{
    if (null === $this->PROPERTY) {
        $this->PROPERTY = new CLASS($value);
    } elseif ([] !== $value) {
        throw new InvalidConfigurationException(\'The node created by "NAME()" has already been initialized. You cannot pass values the second time you call NAME().\');
    }

    return $this->PROPERTY;
}';
        $class->addUse(InvalidConfigurationException::class);
        $class->addMethod($node->getName(), $body, ['PROPERTY' => $property->getName(), 'CLASS' => $childClass->getFqcn()]);

        $this->buildNode($node, $childClass, $this->getSubNamespace($childClass));
    }

    private function handleVariableNode(VariableNode $node, ClassBuilder $class): void
    {
        $comment = $this->getComment($node);
        $property = $class->addProperty($node->getName());
        $class->addUse(ParamConfigurator::class);

        $body = '
/**
COMMENT *
 * @return $this
 */
public function NAME(mixed $valueDEFAULT): static
{
    $this->PROPERTY = $value;

    return $this;
}';
        $class->addMethod($node->getName(), $body, ['PROPERTY' => $property->getName(), 'COMMENT' => $comment, 'DEFAULT' => $node->hasDefaultValue() ? ' = '.var_export($node->getDefaultValue(), true) : '']);
    }

    private function handlePrototypedArrayNode(PrototypedArrayNode $node, ClassBuilder $class, string $namespace): void
    {
        $name = $this->getSingularName($node);
        $prototype = $node->getPrototype();
        $methodName = $name;

        $parameterType = $this->getParameterType($prototype);
        if (null !== $parameterType || $prototype instanceof ScalarNode) {
            $class->addUse(ParamConfigurator::class);
            $property = $class->addProperty($node->getName());
            if (null === $key = $node->getKeyAttribute()) {
                // This is an array of values; don't use singular name
                $body = '
/**
 * @param ParamConfigurator|list<ParamConfigurator|TYPE> $value
 *
 * @return $this
 */
public function NAME(ParamConfigurator|array $value): static
{
    $this->PROPERTY = $value;

    return $this;
}';

                $class->addMethod($node->getName(), $body, ['PROPERTY' => $property->getName(), 'TYPE' => '' === $parameterType ? 'mixed' : $parameterType]);
            } else {
                $body = '
/**
 * @return $this
 */
public function NAME(string $VAR, TYPE $VALUE): static
{
    $this->PROPERTY[$VAR] = $VALUE;

    return $this;
}';

                $class->addMethod($methodName, $body, ['PROPERTY' => $property->getName(), 'TYPE' => '' === $parameterType ? 'mixed' : 'ParamConfigurator|'.$parameterType, 'VAR' => '' === $key ? 'key' : $key, 'VALUE' => 'value' === $key ? 'data' : 'value']);
            }

            return;
        }

        $childClass = new ClassBuilder($namespace, $name);
        if ($prototype instanceof ArrayNode) {
            $childClass->setAllowExtraKeys($prototype->shouldIgnoreExtraKeys());
        }
        $class->addRequire($childClass);
        $this->classes[] = $childClass;
        $property = $class->addProperty($node->getName(), $childClass->getFqcn().'[]');

        if (null === $key = $node->getKeyAttribute()) {
            $body = '
public function NAME(array $value = []): CLASS
{
    return $this->PROPERTY[] = new CLASS($value);
}';
            $class->addMethod($methodName, $body, ['PROPERTY' => $property->getName(), 'CLASS' => $childClass->getFqcn()]);
        } else {
            $body = '
public function NAME(string $VAR, array $VALUE = []): CLASS
{
    if (!isset($this->PROPERTY[$VAR])) {
        return $this->PROPERTY[$VAR] = new CLASS($value);
    }
    if ([] === $value) {
        return $this->PROPERTY[$VAR];
    }

    throw new InvalidConfigurationException(\'The node created by "NAME()" has already been initialized. You cannot pass values the second time you call NAME().\');
}';
            $class->addUse(InvalidConfigurationException::class);
            $class->addMethod($methodName, $body, ['PROPERTY' => $property->getName(), 'CLASS' => $childClass->getFqcn(), 'VAR' => '' === $key ? 'key' : $key, 'VALUE' => 'value' === $key ? 'data' : 'value']);
        }

        $this->buildNode($prototype, $childClass, $namespace.'\\'.$childClass->getName());
    }

    private function handleScalarNode(ScalarNode $node, ClassBuilder $class): void
    {
        $comment = $this->getComment($node);
        $property = $class->addProperty($node->getName());
        $class->addUse(ParamConfigurator::class);

        $body = '
/**
COMMENT * @return $this
 */
public function NAME($value): static
{
    $this->PROPERTY = $value;

    return $this;
}';

        $class->addMethod($node->getName(), $body, ['PROPERTY' => $property->getName(), 'COMMENT' => $comment]);
    }

    private function getParameterType(NodeInterface $node): ?string
    {
        if ($node instanceof BooleanNode) {
            return 'bool';
        }

        if ($node instanceof IntegerNode) {
            return 'int';
        }

        if ($node instanceof FloatNode) {
            return 'float';
        }

        if ($node instanceof EnumNode) {
            return '';
        }

        if ($node instanceof PrototypedArrayNode && $node->getPrototype() instanceof ScalarNode) {
            // This is just an array of variables
            return 'array';
        }

        if ($node instanceof VariableNode) {
            // mixed
            return '';
        }

        return null;
    }

    private function getComment(VariableNode $node): string
    {
        $comment = '';
        if ('' !== $info = (string) $node->getInfo()) {
            $comment .= ' * '.$info."\n";
        }

        foreach ((array) ($node->getExample() ?? []) as $example) {
            $comment .= ' * @example '.$example."\n";
        }

        if ('' !== $default = $node->getDefaultValue()) {
            $comment .= ' * @default '.(null === $default ? 'null' : var_export($default, true))."\n";
        }

        if ($node instanceof EnumNode) {
            $comment .= sprintf(' * @param ParamConfigurator|%s $value', implode('|', array_map(function ($a) {
                return var_export($a, true);
            }, $node->getValues())))."\n";
        } else {
            $parameterType = $this->getParameterType($node);
            if (null === $parameterType || '' === $parameterType) {
                $parameterType = 'mixed';
            }
            $comment .= ' * @param ParamConfigurator|'.$parameterType.' $value'."\n";
        }

        if ($node->isDeprecated()) {
            $comment .= ' * @deprecated '.$node->getDeprecation($node->getName(), $node->getParent()->getName())['message']."\n";
        }

        return $comment;
    }

    /**
     * Pick a good singular name.
     */
    private function getSingularName(PrototypedArrayNode $node): string
    {
        $name = $node->getName();
        if (!str_ends_with($name, 's')) {
            return $name;
        }

        $parent = $node->getParent();
        $mappings = $parent instanceof ArrayNode ? $parent->getXmlRemappings() : [];
        foreach ($mappings as $map) {
            if ($map[1] === $name) {
                $name = $map[0];
                break;
            }
        }

        return $name;
    }

    private function buildToArray(ClassBuilder $class): void
    {
        $body = '$output = [];';
        foreach ($class->getProperties() as $p) {
            $code = '$this->PROPERTY';
            if (null !== $p->getType()) {
                if ($p->isArray()) {
                    $code = 'array_map(function ($v) { return $v->toArray(); }, $this->PROPERTY)';
                } else {
                    $code = '$this->PROPERTY->toArray()';
                }
            }

            $body .= strtr('
    if (null !== $this->PROPERTY) {
        $output[\'ORG_NAME\'] = '.$code.';
    }', ['PROPERTY' => $p->getName(), 'ORG_NAME' => $p->getOriginalName()]);
        }

        $extraKeys = $class->shouldAllowExtraKeys() ? ' + $this->_extraKeys' : '';

        $class->addMethod('toArray', '
public function NAME(): array
{
    '.$body.'

    return $output'.$extraKeys.';
}');
    }

    private function buildConstructor(ClassBuilder $class): void
    {
        $body = '';
        foreach ($class->getProperties() as $p) {
            $code = '$value[\'ORG_NAME\']';
            if (null !== $p->getType()) {
                if ($p->isArray()) {
                    $code = 'array_map(function ($v) { return new '.$p->getType().'($v); }, $value[\'ORG_NAME\'])';
                } else {
                    $code = 'new '.$p->getType().'($value[\'ORG_NAME\'])';
                }
            }

            $body .= strtr('
    if (isset($value[\'ORG_NAME\'])) {
        $this->PROPERTY = '.$code.';
        unset($value[\'ORG_NAME\']);
    }
', ['PROPERTY' => $p->getName(), 'ORG_NAME' => $p->getOriginalName()]);
        }

        if ($class->shouldAllowExtraKeys()) {
            $body .= '
    $this->_extraKeys = $value;
';
        } else {
            $body .= '
    if ([] !== $value) {
        throw new InvalidConfigurationException(sprintf(\'The following keys are not supported by "%s": \', __CLASS__).implode(\', \', array_keys($value)));
    }';

            $class->addUse(InvalidConfigurationException::class);
        }

        $class->addMethod('__construct', '
public function __construct(array $value = [])
{
'.$body.'
}');
    }

    private function buildSetExtraKey(ClassBuilder $class): void
    {
        if (!$class->shouldAllowExtraKeys()) {
            return;
        }

        $class->addUse(ParamConfigurator::class);

        $class->addProperty('_extraKeys');

        $class->addMethod('set', '
/**
 * @param ParamConfigurator|mixed $value
 *
 * @return $this
 */
public function NAME(string $key, mixed $value): static
{
    if (null === $value) {
        unset($this->_extraKeys[$key]);
    } else {
        $this->_extraKeys[$key] = $value;
    }

    return $this;
}');
    }

    private function getSubNamespace(ClassBuilder $rootClass): string
    {
        return sprintf('%s\\%s', $rootClass->getNamespace(), substr($rootClass->getName(), 0, -6));
    }
}
