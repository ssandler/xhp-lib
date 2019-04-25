<?hh // strict
/*
 *  Copyright (c) 2004-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

use type Facebook\TypeAssert\IncorrectTypeException;
use namespace Facebook\TypeAssert;
use namespace Facebook\TypeSpec;
use namespace HH\Lib\{C, Str};


abstract class :x:composable-element extends :xhp {
  private Map<string, mixed> $attributes = Map {};
  private Vector<XHPChild> $children = Vector {};
  private Map<string, mixed> $context = Map {};

  protected function init(): void {
  }

  /**
   * A new :x:composable-element is instantiated for every literal tag
   * expression in the script.
   *
   * The following code:
   * $foo = <foo attr="val">bar</foo>;
   *
   * will execute something like:
   * $foo = new xhp_foo(array('attr' => 'val'), array('bar'));
   *
   * @param $attributes    map of attributes to values
   * @param $children      list of children
   */
  final public function __construct(
    KeyedTraversable<string, mixed> $attributes,
    Traversable<XHPChild> $children,
    dynamic ...$debug_info
  ) {
    parent::__construct($attributes, $children);
    foreach ($children as $child) {
      $this->appendChild($child);
    }

    foreach ($attributes as $key => $value) {
      if (self::isSpreadKey($key)) {
        invariant(
          $value instanceof :x:composable-element,
          "Only XHP can be used with an attribute spread operator",
        );
        $this->spreadElementImpl($value);
      } else {
        $this->setAttribute($key, $value);
      }
    }

    if (:xhp::isChildValidationEnabled()) {
      // There is some cost to having defaulted unused arguments on a function
      // so we leave these out and get them with func_get_args().
      if (C\count($debug_info) >= 2) {
        $this->source = $debug_info[0].':'.$debug_info[1];
      } else {
        $this->source =
          'You have child validation on, but debug information is not being '.
          'passed to XHP objects correctly. Ensure xhp.include_debug is on '.
          'in your PHP configuration. Without this option enabled, '.
          'validation errors will be painful to debug at best.';
      }
    }
    $this->init();
  }

  /**
   * Adds a child to the end of this node. If you give an array to this method
   * then it will behave like a DocumentFragment.
   *
   * @param $child     single child or array of children
   */
  final public function appendChild(mixed $child): this {
    if ($child instanceof Traversable) {
      foreach ($child as $c) {
        $this->appendChild($c);
      }
    } else if ($child instanceof :x:frag) {
      $this->children->addAll($child->getChildren());
    } else if ($child !== null) {
      assert($child instanceof XHPChild);
      $this->children->add($child);
    }
    return $this;
  }

  /**
   * Adds a child to the beginning of this node. If you give an array to this
   * method then it will behave like a DocumentFragment.
   *
   * @param $child     single child or array of children
   */
  final public function prependChild(mixed $child): this {
    // There's no prepend to a Vector, so reverse, append, and reverse agains
    $this->children->reverse();
    $this->appendChild($child);
    $this->children->reverse();
    return $this;
  }

  /**
   * Replaces all children in this node. You may pass a single array or
   * multiple parameters.
   *
   * @param $children  Single child or array of children
   */
  final public function replaceChildren(XHPChild ...$children): this {
    // This function has been micro-optimized
    $new_children = Vector {};
    foreach ($children as $xhp) {
      /* HH_FIXME[4273] bogus "XHPChild always truthy" - FB T41388073 */
    if ($xhp instanceof :x:frag) {
      foreach ($xhp->children as $child) {
        $new_children->add($child);
      }
    } else if (!($xhp instanceof Traversable)) {
      $new_children->add($xhp);
    } else {
      foreach ($xhp as $element) {
        if ($element instanceof :x:frag) {
          foreach ($element->children as $child) {
            $new_children->add($child);
          }
        } else if ($element !== null) {
          $new_children->add($element);
        }
      }
    }
    }
    $this->children = $new_children;
    return $this;
  }

  /**
   * Fetches all direct children of this element that match a particular tag
   * name or category (or all children if none is given)
   *
   * @param $selector   tag name or category (optional)
   * @return array
   */
  final public function getChildren(
    ?string $selector = null,
  ): Vector<XHPChild> {
    if ($selector is string && $selector !== '') {
      $children = Vector {};
      if ($selector[0] == '%') {
        $selector = substr($selector, 1);
        foreach ($this->children as $child) {
          if ($child instanceof :xhp && $child->categoryOf($selector)) {
            $children->add($child);
          }
        }
      } else {
        $selector = :xhp::element2class($selector);
        foreach ($this->children as $child) {
          if (is_a($child, $selector, /* allow strings = */ true)) {
            $children->add($child);
          }
        }
      }
    } else {
      $children = new Vector($this->children);
    }
    return $children;
  }


  /**
   * Fetches the first direct child of the element, or the first child that
   * matches the tag if one is given
   *
   * @param $selector   string   tag name or category (optional)
   * @return            element  the first child node (with the given selector),
   *                             false if there are no (matching) children
   */
  final public function getFirstChild(?string $selector = null): ?XHPChild {
    if ($selector === null) {
      return $this->children->get(0);
    } else if ($selector[0] == '%') {
      $selector = substr($selector, 1);
      foreach ($this->children as $child) {
        if ($child instanceof :xhp && $child->categoryOf($selector)) {
          return $child;
        }
      }
    } else {
      $selector = :xhp::element2class($selector);
      foreach ($this->children as $child) {
        if (is_a($child, $selector, /* allow strings = */ true)) {
          return $child;
        }
      }
    }
    return null;
  }

  /**
   * Fetches the last direct child of the element, or the last child that
   * matches the tag or category if one is given
   *
   * @param $selector  string   tag name or category (optional)
   * @return           element  the last child node (with the given selector),
   *                            false if there are no (matching) children
   */
  final public function getLastChild(?string $selector = null): ?XHPChild {
    $temp = $this->getChildren($selector);
    if ($temp->count() > 0) {
      $count = $temp->count();
      return $temp->at($count - 1);
    }
    return null;
  }

  /**
   * Fetches an attribute from this elements attribute store. If $attr is not
   * defined in the store and is not a data- or aria- attribute an exception
   * will be thrown. An exception will also be thrown if $attr is required and
   * not set.
   *
   * @param $attr      attribute to fetch
   * @return           value
   */
  final public function getAttribute(string $attr): mixed {
    // Return the attribute if it's there
    if ($this->attributes->containsKey($attr)) {
      return $this->attributes->get($attr);
    }

    if (!ReflectionXHPAttribute::IsSpecial($attr)) {
      // Get the declaration
      $decl = static::__xhpReflectionAttribute($attr);

      if ($decl === null) {
        throw new XHPAttributeNotSupportedException($this, $attr);
      } else if ($decl->isRequired()) {
        throw new XHPAttributeRequiredException($this, $attr);
      } else {
        return $decl->getDefaultValue();
      }
    } else {
      return null;
    }
  }

  final public static function __xhpReflectionAttribute(
    string $attr,
  ): ?ReflectionXHPAttribute {
    $map = static::__xhpReflectionAttributes();
    if ($map->containsKey($attr)) {
      return $map[$attr];
    }
    return null;
  }

  <<__MemoizeLSB>>
  final public static function __xhpReflectionAttributes(
  ): Map<string, ReflectionXHPAttribute> {
    $map = Map {};
    $decl = static::__xhpAttributeDeclaration();
    foreach ($decl as $name => $attr_decl) {
      $map[$name] = new ReflectionXHPAttribute($name, $attr_decl);
    }
    return $map;
  }

  <<__MemoizeLSB>>
  final public static function __xhpReflectionChildrenDeclaration(
  ): ReflectionXHPChildrenDeclaration {
    return new ReflectionXHPChildrenDeclaration(
      :xhp::class2element(static::class),
      self::emptyInstance()->__xhpChildrenDeclaration(),
    );
  }

  final public static function __xhpReflectionCategoryDeclaration(
  ): Set<string> {
    return new Set(
      \array_keys(self::emptyInstance()->__xhpCategoryDeclaration()),
    );
  }

  // Work-around to call methods that should be static without a real
  // instance.
  <<__MemoizeLSB>>
  private static function emptyInstance(): this {
    return (
      new \ReflectionClass(static::class)
    )->newInstanceWithoutConstructor();
  }

  final public function getAttributes(): Map<string, mixed> {
    return $this->attributes->toMap();
  }

  /**
   * Determines if a given XHP attribute "key" represents an XHP spread operator
   * in the constructor.
   */
  private static function isSpreadKey(string $key): bool {
    return substr($key, 0, strlen(:xhp::SPREAD_PREFIX)) === :xhp::SPREAD_PREFIX;
  }

  /**
   * Implements the XHP spread operator in expressions like:
   *   <foo attr1="bar" {...$xhp} />
   *
   * This will only copy defined attributes on $xhp to when they are also
   * defined on $this. "Special" data-/aria- attributes will still need to be
   * implicitly transferred, since the typechecker never knows about them.
   *
   * Defaults from $xhp are copied as well, if they are present.
   */
  protected final function spreadElementImpl(
    :x:composable-element $element,
  ): void {
    foreach ($element::__xhpReflectionAttributes() as $attr_name => $attr) {
      $our_attr = static::__xhpReflectionAttribute($attr_name);
      if ($our_attr === null) {
        continue;
      }

      $val = $element->getAttribute($attr_name);
      if ($val === null) {
        continue;
      }

      // If the receiving class has the same attribute and we had a value or
      // a default, then copy it over.
      $this->setAttribute($attr_name, $val);
    }
  }

  /**
   * Sets an attribute in this element's attribute store. If the attribute is
   * not defined in the store and is not a data- or aria- attribute an
   * exception will be thrown. An exception will also be thrown if the
   * attribute value is invalid.
   *
   * @param $attr      attribute to set
   * @param $val       value
   */
  final public function setAttribute(string $attr, mixed $value): this {
    if (!ReflectionXHPAttribute::IsSpecial($attr)) {
      if (:xhp::isAttributeValidationEnabled()) {
        $value = $this->validateAttributeValue($attr, $value);
      }
    } else {
      $value = $value;
    }
    $this->attributes->set($attr, $value);
    return $this;
  }

  /**
   * Takes an array of key/value pairs and adds each as an attribute.
   *
   * @param $attrs    array of attributes
   */
  final public function setAttributes(
    KeyedTraversable<string, mixed> $attrs,
  ): this {
    foreach ($attrs as $key => $value) {
      $this->setAttribute($key, $value);
    }
    return $this;
  }

  /**
   * Whether the attribute has been explicitly set to a non-null value by the
   * caller (vs. using the default set by "attribute" in the class definition).
   *
   * @param $attr attribute to check
   */
  final public function isAttributeSet(string $attr): bool {
    return $this->attributes->containsKey($attr);
  }

  /**
   * Removes an attribute from this element's attribute store. An exception
   * will be thrown if $attr is not supported.
   *
   * @param $attr      attribute to remove
   * @param $val       value
   */
  final public function removeAttribute(string $attr): this {
    if (!ReflectionXHPAttribute::IsSpecial($attr)) {
      if (:xhp::isAttributeValidationEnabled()) {
        $value = $this->validateAttributeValue($attr, null);
      }
    }
    $this->attributes->removeKey($attr);
    return $this;
  }

  /**
   * Sets an attribute in this element's attribute store. Always foregoes
   * validation.
   *
   * @param $attr      attribute to set
   * @param $val       value
   */
  final public function forceAttribute(string $attr, mixed $value): this {
    $this->attributes->set($attr, $value);
    return $this;
  }
  /**
   * Returns all contexts currently set.
   *
   * @return array  All contexts
   */
  final public function getAllContexts(): Map<string, mixed> {
    return $this->context->toMap();
  }

  /**
   * Returns a specific context value. Can include a default if not set.
   *
   * @param string $key     The context key
   * @param mixed $default  The value to return if not set (optional)
   * @return mixed          The context value or $default
   */
  final public function getContext(string $key, mixed $default = null): mixed {
    if ($this->context->containsKey($key)) {
      return $this->context->get($key);
    }
    return $default;
  }

  /**
   * Sets a value that will be automatically passed down through a render chain
   * and can be referenced by children and composed elements. For instance, if
   * a root element sets a context of "admin_mode" = true, then all elements
   * that are rendered as children of that root element will receive this
   * context WHEN RENDERED. The context will not be available before render.
   *
   * @param mixed $key      Either a key, or an array of key/value pairs
   * @param mixed $default  if $key is a string, the value to set
   * @return :xhp           $this
   */
  final public function setContext(string $key, mixed $value): this {
    $this->context->set($key, $value);
    return $this;
  }

  /**
   * Sets a value that will be automatically passed down through a render chain
   * and can be referenced by children and composed elements. For instance, if
   * a root element sets a context of "admin_mode" = true, then all elements
   * that are rendered as children of that root element will receive this
   * context WHEN RENDERED. The context will not be available before render.
   *
   * @param Map $context  A map of key/value pairs
   * @return :xhp         $this
   */
  final public function addContextMap(Map<string, mixed> $context): this {
    $this->context->setAll($context);
    return $this;
  }

  /**
   * Transfers the context but will not overwrite anything. This is done only
   * for rendering because we don't want a parent's context to replace a
   * child's context if they have the same key.
   *
   * @param array $parentContext  The context to transfer
   */
  final protected function __transferContext(
    Map<string, mixed> $parentContext,
  ): void {
    foreach ($parentContext as $key => $value) {
      if (!$this->context->containsKey($key)) {
        $this->context->set($key, $value);
      }
    }
  }

  abstract protected function __flushSubtree(): Awaitable<:x:primitive>;

  /**
   * Defined in elements by the `attribute` keyword. The declaration is simple.
   * There is a keyed array, with each key being an attribute. Each value is
   * an array with 4 elements. The first is the attribute type. The second is
   * meta-data about the attribute. The third is a default value (null for
   * none). And the fourth is whether or not this value is required.
   *
   * Attribute types are suggested by the TYPE_* constants.
   */
  protected static function __xhpAttributeDeclaration(
  ): darray<string, darray<int, mixed>> {
    return darray[];
  }

  /**
   * Defined in elements by the `category` keyword. This is just a list of all
   * categories an element belongs to. Each category is a key with value 1.
   */
  protected function __xhpCategoryDeclaration(): darray<string, int> {
    return darray[];
  }

  /**
   * Defined in elements by the `children` keyword. This returns a pattern of
   * allowed children. The return value is potentially very complicated. The
   * two simplest are 0 and 1 which mean no children and any children,
   * respectively. Otherwise you're dealing with an array which is just the
   * biggest mess you've ever seen.
   */
  protected function __xhpChildrenDeclaration(): mixed {
    return 1;
  }

  /**
   * Throws an exception if $val is not a valid value for the attribute $attr
   * on this element.
   */
  final protected function validateAttributeValue<T>(
    string $attr,
    T $val,
  ): mixed {
    $decl = static::__xhpReflectionAttribute($attr);
    if ($decl === null) {
      throw new XHPAttributeNotSupportedException($this, $attr);
    }
    if ($val === null) {
      return null;
    }
    switch ($decl->getValueType()) {
      case XHPAttributeType::TYPE_STRING:
        if (!($val is string)) {
          $val = XHPAttributeCoercion::CoerceToString($this, $attr, $val);
        }
        break;

      case XHPAttributeType::TYPE_BOOL:
        if (!($val is bool)) {
          $val = XHPAttributeCoercion::CoerceToBool($this, $attr, $val);
        }
        break;

      case XHPAttributeType::TYPE_INTEGER:
        if (!($val is int)) {
          $val = XHPAttributeCoercion::CoerceToInt($this, $attr, $val);
        }
        break;

      case XHPAttributeType::TYPE_FLOAT:
        if (!($val is float)) {
          $val = XHPAttributeCoercion::CoerceToFloat($this, $attr, $val);
        }
        break;

      case XHPAttributeType::TYPE_ARRAY:
        if (!is_array($val)) {
          throw new XHPInvalidAttributeException($this, 'array', $attr, $val);
        }
        break;

      case XHPAttributeType::TYPE_OBJECT:
        $class = $decl->getValueClass();
        if (is_a($val, $class, true)) {
          break;
        }
        /* HH_FIXME[4026] $class as enumname<_> */
        if (enum_exists($class) && $class::isValid($val)) {
          break;
        }
        // Things that are a valid array key without any coercion
        if ($class === 'HH\arraykey') {
          if (($val is int) || ($val is string)) {
            break;
          }
        }
        if ($class === 'HH\num') {
          if (($val is int) || ($val is float)) {
            break;
          }
        }
        if (is_array($val)) {
          try {
            $type_structure = (
              new ReflectionTypeAlias($class)
            )->getResolvedTypeStructure();
            /* HH_FIXME[4110] $type_structure is an array, but should be a
             * TypeStructure<T> */
            TypeAssert\matches_type_structure($type_structure, $val);
            break;
          } catch (ReflectionException $_) {
            // handled below
          } catch (IncorrectTypeException $_) {
            // handled below
          }
        }
        throw new XHPInvalidAttributeException($this, $class, $attr, $val);
        break;

      case XHPAttributeType::TYPE_VAR:
        break;

      case XHPAttributeType::TYPE_ENUM:
        if (!(($val is string) && $decl->getEnumValues()->contains($val))) {
          $enums = 'enum("'.implode('","', $decl->getEnumValues()).'")';
          throw new XHPInvalidAttributeException($this, $enums, $attr, $val);
        }
        break;

      case XHPAttributeType::TYPE_UNSUPPORTED_LEGACY_CALLABLE:
        throw new XHPUnsupportedAttributeTypeException(
          $this,
          'callable',
          $attr,
          'not supported in XHP-Lib 2.0 or higher.',
        );
    }
    return $val;
  }

  /**
   * Validates that this element's children match its children descriptor, and
   * throws an exception if that's not the case.
   */
  final protected function validateChildren(): void {
    $decl = self::__xhpReflectionChildrenDeclaration();
    $type = $decl->getType();
    if ($type === XHPChildrenDeclarationType::ANY_CHILDREN) {
      return;
    }
    if ($type === XHPChildrenDeclarationType::NO_CHILDREN) {
      if ($this->children) {
        throw new XHPInvalidChildrenException($this, 0);
      } else {
        return;
      }
    }
    list($ret, $ii) = $this->validateChildrenExpression(
      $decl->getExpression(),
      0,
    );
    if (!$ret || $ii < count($this->children)) {
      if (($this->children[$ii] ?? null) is XHPAlwaysValidChild) {
        return;
      }
      throw new XHPInvalidChildrenException($this, $ii);
    }
  }

  final private function validateChildrenExpression(
    ReflectionXHPChildrenExpression $expr,
    int $index,
  ): (bool, int) {
    switch ($expr->getType()) {
      case XHPChildrenExpressionType::SINGLE:
        // Exactly once -- :fb-thing
        return $this->validateChildrenRule($expr, $index);
      case XHPChildrenExpressionType::ANY_NUMBER:
        // Zero or more times -- :fb-thing*
        do {
          list($ret, $index) = $this->validateChildrenRule($expr, $index);
        } while ($ret);
        return tuple(true, $index);

      case XHPChildrenExpressionType::ZERO_OR_ONE:
        // Zero or one times -- :fb-thing?
        list($_, $index) = $this->validateChildrenRule($expr, $index);
        return tuple(true, $index);

      case XHPChildrenExpressionType::ONE_OR_MORE:
        // One or more times -- :fb-thing+
        list($ret, $index) = $this->validateChildrenRule($expr, $index);
        if (!$ret) {
          return tuple(false, $index);
        }
        do {
          list($ret, $index) = $this->validateChildrenRule($expr, $index);
        } while ($ret);
        return tuple(true, $index);

      case XHPChildrenExpressionType::SUB_EXPR_SEQUENCE:
        // Specific order -- :fb-thing, :fb-other-thing
        $oindex = $index;
        list($sub_expr_1, $sub_expr_2) = $expr->getSubExpressions();
        list($ret, $index) = $this->validateChildrenExpression(
          $sub_expr_1,
          $index,
        );
        if ($ret) {
          list($ret, $index) = $this->validateChildrenExpression(
            $sub_expr_2,
            $index,
          );
        }
        if ($ret) {
          return tuple(true, $index);
        }
        return tuple(false, $oindex);

      case XHPChildrenExpressionType::SUB_EXPR_DISJUNCTION:
        // Either or -- :fb-thing | :fb-other-thing
        $oindex = $index;
        list($sub_expr_1, $sub_expr_2) = $expr->getSubExpressions();
        list($ret, $index) = $this->validateChildrenExpression(
          $sub_expr_1,
          $index,
        );
        if (!$ret) {
          list($ret, $index) = $this->validateChildrenExpression(
            $sub_expr_2,
            $index,
          );
        }
        if ($ret) {
          return tuple(true, $index);
        }
        return tuple(false, $oindex);
    }
  }

  final private function validateChildrenRule(
    ReflectionXHPChildrenExpression $expr,
    int $index,
  ): (bool, int) {
    switch ($expr->getConstraintType()) {
      case XHPChildrenConstraintType::ANY:
        if ($this->children->containsKey($index)) {
          return tuple(true, $index + 1);
        }
        return tuple(false, $index);

      case XHPChildrenConstraintType::PCDATA:
        if (
          $this->children->containsKey($index) &&
          !($this->children->get($index) instanceof :xhp)
        ) {
          return tuple(true, $index + 1);
        }
        return tuple(false, $index);

      case XHPChildrenConstraintType::ELEMENT:
        $class = $expr->getConstraintString();
        if (
          $this->children->containsKey($index) &&
          is_a($this->children->get($index), $class, true)
        ) {
          return tuple(true, $index + 1);
        }
        return tuple(false, $index);

      case XHPChildrenConstraintType::CATEGORY:
        if (
          !$this->children->containsKey($index) ||
          !($this->children->get($index) instanceof :xhp)
        ) {
          return tuple(false, $index);
        }
        $category = :xhp::class2element($expr->getConstraintString());
        $child = $this->children->get($index);
        assert($child instanceof :xhp);
        $categories = $child->__xhpCategoryDeclaration();
        if (($categories[$category] ?? 0) === 0) {
          return tuple(false, $index);
        }
        return tuple(true, $index + 1);

      case XHPChildrenConstraintType::SUB_EXPR:
        return $this->validateChildrenExpression(
          $expr->getSubExpression(),
          $index,
        );
    }
  }

  /**
   * Returns the human-readable `children` declaration as seen in this class's
   * source code.
   *
   * Keeping this wrapper around reflection, as it fits well with
   * __getChildrenDescription.
   */
  public function __getChildrenDeclaration(): string {
    return self::__xhpReflectionChildrenDeclaration()->__toString();
  }

  /**
   * Returns a description of the current children in this element. Maybe
   * something like this:
   * <div><span>foo</span>bar</div> ->
   * :span[%inline],pcdata
   */
  final public function __getChildrenDescription(): string {
    $desc = array();
    foreach ($this->children as $child) {
      if ($child instanceof :xhp) {
        $tmp = ':'.:xhp::class2element(get_class($child));
        if ($categories = $child->__xhpCategoryDeclaration()) {
          $tmp .= '[%'.implode(',%', array_keys($categories)).']';
        }
        $desc[] = $tmp;
      } else {
        $desc[] = 'pcdata';
      }
    }
    return implode(',', $desc);
  }

  final public function categoryOf(string $c): bool {
    $categories = $this->__xhpCategoryDeclaration();
    if ($categories[$c] ?? null !== null) {
      return true;
    }
    // XHP parses the category string
    $c = str_replace(array(':', '-'), array('__', '_'), $c);
    return ($categories[$c] ?? null) !== null;
  }
}

/**
 * :x:element defines an interface that all user-land elements should subclass
 * from. The main difference between :x:element and :x:primitive is that
 * subclasses of :x:element should implement `render()` instead of `stringify`.
 * This is important because most elements should not be dealing with strings
 * of markup.
 */
abstract class :x:element extends :x:composable-element implements XHPRoot {
  abstract protected function render(): XHPRoot;

  final public function toString(): string {
    return \HH\Asio\join($this->asyncToString());
  }

  final public async function asyncToString(): Awaitable<string> {
    $that = await $this->__flushRenderedRootElement();
    $ret = await $that->asyncToString();
    return $ret;
  }

  final protected async function __flushSubtree(): Awaitable<:x:primitive> {
    $that = await $this->__flushRenderedRootElement();
    return await $that->__flushSubtree();
  }

  protected async function __renderAndProcess(): Awaitable<XHPRoot> {
    if (:xhp::isChildValidationEnabled()) {
      $this->validateChildren();
    }

    if ($this instanceof XHPAwaitable) {
      // UNSAFE - interfaces don't support 'protected': facebook/hhvm#4830
      $composed = await $this->asyncRender();
    } else {
      $composed = $this->render();
    }

    $composed->__transferContext($this->getAllContexts());
    if ($this instanceof XHPHasTransferAttributes) {
      $this->transferAttributesToRenderedRoot($composed);
    }

    return $composed;
  }

  final protected async function __flushRenderedRootElement(
  ): Awaitable<:x:primitive> {
    $that = $this;
    // Flush root elements returned from render() to an :x:primitive
    while ($that instanceof :x:element) {
      $that = await $that->__renderAndProcess();
    }

    if ($that instanceof :x:primitive) {
      return $that;
    }

    // render() must always (eventually) return :x:primitive
    throw new XHPCoreRenderException($this, $that);
  }
}

/**
 * An <x:frag /> is a transparent wrapper around any number of elements. When
 * you render it just the children will be rendered. When you append it to an
 * element the <x:frag /> will disappear and each child will be sequentially
 * appended to the element.
 */
class :x:frag extends :x:primitive {
  protected function stringify(): string {
    $buf = '';
    foreach ($this->getChildren() as $child) {
      $buf .= :xhp::renderChild($child);
    }
    return $buf;
  }
}


/**
 * :x:primitive lays down the foundation for very low-level elements. You
 * should directly :x:primitive only if you are creating a core element that
 * needs to directly implement stringify(). All other elements should subclass
 * from :x:element.
 */
abstract class :x:primitive extends :x:composable-element implements XHPRoot {
  abstract protected function stringify(): string;

  final public function toString(): string {
    return \HH\Asio\join($this->asyncToString());
  }

  final public async function asyncToString(): Awaitable<string> {
    $that = await $this->__flushSubtree();
    return $that->stringify();
  }

  final private async function __flushElementChildren(): Awaitable<void> {
    $children = $this->getChildren();
    $awaitables = Map {};
    foreach ($children as $idx => $child) {
      if ($child instanceof :x:composable-element) {
        $child->__transferContext($this->getAllContexts());
        $awaitables[$idx] = $child->__flushSubtree();
      }
    }
    if ($awaitables) {
      $awaited = await HH\Asio\m($awaitables);
      foreach ($awaited as $idx => $child) {
        $children[$idx] = $child;
      }
    }
    $this->replaceChildren($children);
  }

  final protected async function __flushSubtree(): Awaitable<:x:primitive> {
    await $this->__flushElementChildren();
    if (:xhp::isChildValidationEnabled()) {
      $this->validateChildren();
    }
    return $this;
  }
}



enum XHPAttributeType: int {
  TYPE_STRING = 1;
  TYPE_BOOL = 2;
  TYPE_INTEGER = 3;
  TYPE_ARRAY = 4;
  TYPE_OBJECT = 5;
  TYPE_VAR = 6;
  TYPE_ENUM = 7;
  TYPE_FLOAT = 8;
  TYPE_UNSUPPORTED_LEGACY_CALLABLE = 9;
}

class ReflectionXHPAttribute {
  private XHPAttributeType $type;
  /*
   * OBJECT: string (class name)
   * ENUM: array<string> (enum values)
   * ARRAY: Array decl
   */
  private mixed $extraType;
  private mixed $defaultValue;
  private bool $required;

  private static ImmSet<string> $specialAttributes = ImmSet { 'data', 'aria' };

  public function __construct(private string $name, array<int, mixed> $decl) {
    $this->type = XHPAttributeType::assert($decl[0]);
    $this->extraType = $decl[1];
    $this->defaultValue = $decl[2];
    $this->required = (bool)$decl[3];
  }

  public function getName(): string {
    return $this->name;
  }

  public function getValueType(): XHPAttributeType {
    return $this->type;
  }

  public function isRequired(): bool {
    return $this->required;
  }

  public function hasDefaultValue(): bool {
    return $this->defaultValue !== null;
  }

  public function getDefaultValue(): mixed {
    return $this->defaultValue;
  }

  <<__Memoize>>
  public function getValueClass(): string {
    $t = $this->getValueType();
    invariant(
      $this->getValueType() === XHPAttributeType::TYPE_OBJECT,
      'Tried to get value class for attribute %s of type %s - needed '.'OBJECT',
      $this->getName(),
      XHPAttributeType::getNames()[$this->getValueType()],
    );
    $v = $this->extraType;
    invariant(
      $v is string,
      'Class name for attribute %s is not a string',
      $this->getName(),
    );
    return $v;
  }

  <<__Memoize>>
  public function getEnumValues(): Set<string> {
    $t = $this->getValueType();
    invariant(
      $this->getValueType() === XHPAttributeType::TYPE_ENUM,
      'Tried to get enum values for attribute %s of type %s - needed '.'ENUM',
      $this->getName(),
      XHPAttributeType::getNames()[$this->getValueType()],
    );
    $v = $this->extraType;
    invariant(
      is_array($v),
      'Class name for attribute %s is not a string',
      $this->getName(),
    );
    return new Set($v);
  }

  /**
   * Returns true if the attribute is a data- or aria- attribute.
   */
  <<__Memoize>>
  public static function IsSpecial(string $attr): bool {
    return strlen($attr) >= 6 &&
      $attr[4] === '-' &&
      self::$specialAttributes->contains(substr($attr, 0, 4));
  }

  public function __toString(): string {
    switch ($this->getValueType()) {
      case XHPAttributeType::TYPE_STRING:
        $out = 'string';
        break;
      case XHPAttributeType::TYPE_BOOL:
        $out = 'bool';
        break;
      case XHPAttributeType::TYPE_INTEGER:
        $out = 'int';
        break;
      case XHPAttributeType::TYPE_ARRAY:
        $out = 'array';
        break;
      case XHPAttributeType::TYPE_OBJECT:
        $out = $this->getValueClass();
        break;
      case XHPAttributeType::TYPE_VAR:
        $out = 'mixed';
        break;
      case XHPAttributeType::TYPE_ENUM:
        $out = 'enum {';
        $out .= implode(', ', $this->getEnumValues()->map($x ==> "'".$x."'"));
        $out .= '}';
        break;
      case XHPAttributeType::TYPE_FLOAT:
        $out = 'float';
        break;
      case XHPAttributeType::TYPE_UNSUPPORTED_LEGACY_CALLABLE:
        $out = '<UNSUPPORTED: legacy callable>';
        break;
    }
    $out .= ' '.$this->getName();
    if ($this->hasDefaultValue()) {
      $out .= ' = '.var_export($this->getDefaultValue(), true);
    }
    if ($this->isRequired()) {
      $out .= ' @required';
    }
    return $out;
  }
}



enum XHPChildrenDeclarationType: int {
  NO_CHILDREN = 0;
  ANY_CHILDREN = 1;
  EXPRESSION = ~0;
}

enum XHPChildrenExpressionType: int {
  SINGLE = 0; // :thing
  ANY_NUMBER = 1; // :thing*
  ZERO_OR_ONE = 2; // :thing?
  ONE_OR_MORE = 3; // :thing+
  SUB_EXPR_SEQUENCE = 4; // (expr, expr)
  SUB_EXPR_DISJUNCTION = 5; // (expr | expr)
}

enum XHPChildrenConstraintType: int {
  ANY = 1;
  PCDATA = 2;
  ELEMENT = 3;
  CATEGORY = 4;
  SUB_EXPR = 5;
}

class ReflectionXHPChildrenDeclaration {
  public function __construct(private string $context, private mixed $data) {
  }

  <<__Memoize>>
  public function getType(): XHPChildrenDeclarationType {
    if (is_array($this->data)) {
      return XHPChildrenDeclarationType::EXPRESSION;
    }
    return XHPChildrenDeclarationType::assert($this->data);
  }

  <<__Memoize>>
  public function getExpression(): ReflectionXHPChildrenExpression {
    try {
      $data = TypeSpec\dict_like_array(TypeSpec\int(), TypeSpec\mixed())
        ->assertType($this->data);
      return new ReflectionXHPChildrenExpression($this->context, $data);
    } catch (IncorrectTypeException $_) {
      // handled below
    }

    throw new Exception (
      "Tried to get child expression for XHP class ".
      :xhp::class2element(get_class($this->context)).
      ", but it does not have an expressions."
    );
  }

  public function __toString(): string {
    if ($this->getType() === XHPChildrenDeclarationType::ANY_CHILDREN) {
      return 'any';
    }
    if ($this->getType() === XHPChildrenDeclarationType::NO_CHILDREN) {
      return 'empty';
    }
    return $this->getExpression()->__toString();
  }
}

class ReflectionXHPChildrenExpression {
  public function __construct(
    private string $context,
    private array<int, mixed> $data,
  ) {
  }

  <<__Memoize>>
  public function getType(): XHPChildrenExpressionType {
    return XHPChildrenExpressionType::assert($this->data[0]);
  }

  <<__Memoize>>
  public function getSubExpressions(
  ): (ReflectionXHPChildrenExpression, ReflectionXHPChildrenExpression) {
    $type = $this->getType();
    invariant(
      $type === XHPChildrenExpressionType::SUB_EXPR_SEQUENCE ||
      $type === XHPChildrenExpressionType::SUB_EXPR_DISJUNCTION,
      'Only disjunctions and sequences have two sub-expressions - in %s',
      :xhp::class2element(get_class($this->context)),
    );
    try {
      $sub_expr_1 = TypeSpec\dict_like_array(TypeSpec\int(), TypeSpec\mixed())
        ->assertType($this->data[1]);
      $sub_expr_2 = TypeSpec\dict_like_array(TypeSpec\int(), TypeSpec\mixed())
        ->assertType($this->data[2]);
      return tuple(
        new ReflectionXHPChildrenExpression($this->context, $sub_expr_1),
        new ReflectionXHPChildrenExpression($this->context, $sub_expr_2),
      );
    } catch (IncorrectTypeException $_) {
      // handled below
    }

    throw new Exception('Data is not subexpressions - in '.$this->context);
  }

  <<__Memoize>>
  public function getConstraintType(): XHPChildrenConstraintType {
    $type = $this->getType();
    invariant(
      $type !== XHPChildrenExpressionType::SUB_EXPR_SEQUENCE &&
      $type !== XHPChildrenExpressionType::SUB_EXPR_DISJUNCTION,
      'Disjunctions and sequences do not have a constraint type - in %s',
      :xhp::class2element(get_class($this->context)),
    );
    return XHPChildrenConstraintType::assert($this->data[1]);
  }

  <<__Memoize>>
  public function getConstraintString(): string {
    $type = $this->getConstraintType();
    invariant(
      $type === XHPChildrenConstraintType::ELEMENT ||
      $type === XHPChildrenConstraintType::CATEGORY,
      'Only element and category constraints have string data - in %s',
      :xhp::class2element(get_class($this->context)),
    );
    $data = $this->data[2];
    invariant($data is string, 'Expected string data');
    return $data;
  }

  <<__Memoize>>
  public function getSubExpression(): ReflectionXHPChildrenExpression {
    invariant(
      $this->getConstraintType() === XHPChildrenConstraintType::SUB_EXPR,
      'Only expression constraints have a single sub-expression - in %s',
      $this->context,
    );
    $data = $this->data[2];
    try {
      $data = TypeSpec\dict_like_array(TypeSpec\int(), TypeSpec\mixed())
        ->assertType($this->data[2]);
      return new ReflectionXHPChildrenExpression($this->context, $data);
    } catch (IncorrectTypeException $_) {
      // handled below
    }

    throw new Exception (
      'Expected a sub-expression, got a '.
      (is_object($data) ? get_class($data) : gettype($data)).
      ' - in '. $this->context
    );
  }

  public function __toString(): string {
    switch ($this->getType()) {
      case XHPChildrenExpressionType::SINGLE:
        return $this->__constraintToString();

      case XHPChildrenExpressionType::ANY_NUMBER:
        return $this->__constraintToString().'*';

      case XHPChildrenExpressionType::ZERO_OR_ONE:
        return $this->__constraintToString().'?';

      case XHPChildrenExpressionType::ONE_OR_MORE:
        return $this->__constraintToString().'+';

      case XHPChildrenExpressionType::SUB_EXPR_SEQUENCE:
        list($e1, $e2) = $this->getSubExpressions();
        return $e1->__toString().','.$e2->__toString();

      case XHPChildrenExpressionType::SUB_EXPR_DISJUNCTION:
        list($e1, $e2) = $this->getSubExpressions();
        return $e1->__toString().'|'.$e2->__toString();
    }
  }

  private function __constraintToString(): string {
    switch ($this->getConstraintType()) {
      case XHPChildrenConstraintType::ANY:
        return 'any';

      case XHPChildrenConstraintType::PCDATA:
        return 'pcdata';

      case XHPChildrenConstraintType::ELEMENT:
        return ':'.:xhp::class2element($this->getConstraintString());

      case XHPChildrenConstraintType::CATEGORY:
        return '%'.$this->getConstraintString();

      case XHPChildrenConstraintType::SUB_EXPR:
        return '('.$this->getSubExpression()->__toString().')';
    }
  }
}


class ReflectionXHPClass {
  public function __construct(private classname<:x:composable-element> $className) {
    invariant(
      class_exists($this->className),
      'Invalid class name: %s',
      $this->className,
    );
  }

  public function getReflectionClass(): ReflectionClass {
    return new ReflectionClass($this->getClassName());
  }

  public function getClassName(): classname<:x:composable-element> {
    return $this->className;
  }

  public function getElementName(): string {
    return :xhp::class2element($this->getClassName());
  }

  public function getChildren(): ReflectionXHPChildrenDeclaration {
    $class = $this->getClassName();
    return $class::__xhpReflectionChildrenDeclaration();
  }

  public function getAttribute(string $name): ReflectionXHPAttribute {
    $map = $this->getAttributes();
    invariant(
      $map->containsKey($name),
      'Tried to get attribute %s for XHP element %s, which does not exist',
      $name,
      $this->getElementName(),
    );
    return $map[$name];
  }

  public function getAttributes(): Map<string, ReflectionXHPAttribute> {
    $class = $this->getClassName();
    return $class::__xhpReflectionAttributes();
  }

  public function getCategories(): Set<string> {
    $class = $this->getClassName();
    return $class::__xhpReflectionCategoryDeclaration();
  }
}



abstract class :xhp implements XHPChild, JsonSerializable {
  // Must be kept in sync with code generation for XHP
  const string SPREAD_PREFIX = '...$';

  public function __construct(
    KeyedTraversable<string, mixed> $attributes,
    Traversable<XHPChild> $children,
  ): void {
  }
  abstract public function appendChild(mixed $child): this;
  abstract public function prependChild(mixed $child): this;
  abstract public function replaceChildren(...): this;
  abstract public function getChildren(
    ?string $selector = null,
  ): Vector<XHPChild>;
  abstract public function getFirstChild(?string $selector = null): ?XHPChild;
  abstract public function getLastChild(?string $selector = null): ?XHPChild;
  abstract public function getAttribute(string $attr): mixed;
  abstract public function getAttributes(): Map<string, mixed>;
  abstract public function setAttribute(string $attr, mixed $val): this;
  abstract public function setAttributes(
    KeyedTraversable<string, mixed> $attrs,
  ): this;
  abstract public function isAttributeSet(string $attr): bool;
  abstract public function removeAttribute(string $attr): this;
  abstract public function categoryOf(string $cat): bool;
  abstract public function toString(): string;
  abstract protected function __xhpCategoryDeclaration(): darray<string, int>;
  abstract protected function __xhpChildrenDeclaration(): mixed;
  protected static function __xhpAttributeDeclaration(
  ): darray<string, darray<int, mixed>> {
    return darray[];
  }

  public ?string $source;

  /**
   * Enabling validation will give you stricter documents; you won't be able to
   * do many things that violate the XHTML 1.0 Strict spec. It is recommend that
   * you leave this on because otherwise things like the `children` keyword will
   * do nothing. This validation comes at some CPU cost, however, so if you are
   * running a high-traffic site you will probably want to disable this in
   * production. You should still leave it on while developing new features,
   * though.
   */
  private static bool $validateChildren = true;
  private static bool $validateAttributes = false;

  public static function disableChildValidation(): void {
    self::$validateChildren = false;
  }

  public static function enableChildValidation(): void {
    self::$validateChildren = true;
  }

  public static function isChildValidationEnabled(): bool {
    return self::$validateChildren;
  }

  public static function disableAttributeValidation(): void {
    self::$validateAttributes = false;
  }

  public static function enableAttributeValidation(): void {
    self::$validateAttributes = true;
  }

  public static function isAttributeValidationEnabled(): bool {
    return self::$validateAttributes;
  }

  final public function __toString(): string {
    return $this->toString();
  }

  final public function jsonSerialize(): string {
    return $this->toString();
  }

  final protected static function renderChild(XHPChild $child): string {
    if ($child instanceof :xhp) {
      return $child->toString();
    }
    if ($child instanceof XHPUnsafeRenderable) {
      return $child->toHTMLString();
    }
    if ($child instanceof Traversable) {
      throw new XHPRenderArrayException('Can not render traversables!');
    }

    /* HH_FIXME[4281] stringish migration */
    return htmlspecialchars((string)$child);
  }

  public static function element2class(string $element): string {
    return 'xhp_'.str_replace(array(':', '-'), array('__', '_'), $element);
  }

  public static function class2element(string $class): string {
    return str_replace(
      array('__', '_'),
      array(':', '-'),
      preg_replace('#^xhp_#i', '', $class),
    );
  }
}



enum XHPAttributeCoercionMode: int {
  SILENT = 1; // You're a bad person
  LOG_DEPRECATION = 2; // Default in 2.0
  THROW_EXCEPTION = 3; // Default for 2.1
}

abstract final class XHPAttributeCoercion {
  private static XHPAttributeCoercionMode $mode =
    XHPAttributeCoercionMode::THROW_EXCEPTION;

  public static function GetMode(): XHPAttributeCoercionMode {
    return self::$mode;
  }

  public static function SetMode(XHPAttributeCoercionMode $mode): void {
    self::$mode = $mode;
  }

  private static function LogCoercion(
    :x:composable-element $context,
    string $what,
    string $attr,
    mixed $val,
  ): void {
    switch (self::GetMode()) {
      case XHPAttributeCoercionMode::SILENT:
        // Your forward compatibility is bad, and you should feel bad.
        return;
      case XHPAttributeCoercionMode::LOG_DEPRECATION:
        if (is_object($val)) {
          $val_type = get_class($val);
        } else {
          $val_type = gettype($val);
        }
        trigger_error(
          sprintf(
            'Coercing value of type `%s` to `%s` for attribute `%s` of '.
            'element `%s`',
            $val_type,
            $what,
            $attr,
            :xhp::class2element(get_class($context)),
          ),
          E_USER_DEPRECATED,
        );
        return;
      case XHPAttributeCoercionMode::THROW_EXCEPTION:
        throw new XHPInvalidAttributeException($context, $what, $attr, $val);
    }
  }

  public static function CoerceToString(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): string {
    self::LogCoercion($context, 'string', $attr, $val);
    if (($val is int) || ($val is float) || $val instanceof Stringish) {
      return (string)$val;
    }

    throw new XHPInvalidAttributeException($context, 'string', $attr, $val);
  }

  public static function CoerceToInt(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): int {
    self::LogCoercion($context, 'int', $attr, $val);
    if (
      (($val is string) && is_numeric($val) && $val !== '') || ($val is float)
    ) {
      return (int)$val;
    }

    throw new XHPInvalidAttributeException($context, 'int', $attr, $val);
  }

  public static function CoerceToBool(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): bool {
    self::LogCoercion($context, 'bool', $attr, $val);
    if ($val === 'true' || $val === 1 || $val === '1' || $val === $attr) {
      return true;
    }

    if ($val === 'false' || $val === 0 || $val === '0') {
      return false;
    }

    throw new XHPInvalidAttributeException($context, 'bool', $attr, $val);
  }

  public static function CoerceToFloat(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): float {
    self::LogCoercion($context, 'float', $attr, $val);
    if (is_numeric($val)) {
      return (float)$val;
    }

    throw new XHPInvalidAttributeException($context, 'float', $attr, $val);
  }
}


abstract final class XHPAttributeCoercionTwo {
  private static XHPAttributeCoercionMode $mode =
    XHPAttributeCoercionMode::THROW_EXCEPTION;

  public static function GetMode(): XHPAttributeCoercionMode {
    return self::$mode;
  }

  public static function SetMode(XHPAttributeCoercionMode $mode): void {
    self::$mode = $mode;
  }

  private static function LogCoercion(
    :x:composable-element $context,
    string $what,
    string $attr,
    mixed $val,
  ): void {
    switch (self::GetMode()) {
      case XHPAttributeCoercionMode::SILENT:
        // Your forward compatibility is bad, and you should feel bad.
        return;
      case XHPAttributeCoercionMode::LOG_DEPRECATION:
        if (is_object($val)) {
          $val_type = get_class($val);
        } else {
          $val_type = gettype($val);
        }
        trigger_error(
          sprintf(
            'Coercing value of type `%s` to `%s` for attribute `%s` of '.
            'element `%s`',
            $val_type,
            $what,
            $attr,
            :xhp::class2element(get_class($context)),
          ),
          E_USER_DEPRECATED,
        );
        return;
      case XHPAttributeCoercionMode::THROW_EXCEPTION:
        throw new XHPInvalidAttributeException($context, $what, $attr, $val);
    }
  }

  public static function CoerceToString(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): string {
    self::LogCoercion($context, 'string', $attr, $val);
    if (($val is int) || ($val is float) || $val instanceof Stringish) {
      return (string)$val;
    }

    throw new XHPInvalidAttributeException($context, 'string', $attr, $val);
  }

  public static function CoerceToInt(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): int {
    self::LogCoercion($context, 'int', $attr, $val);
    if (
      (($val is string) && is_numeric($val) && $val !== '') || ($val is float)
    ) {
      return (int)$val;
    }

    throw new XHPInvalidAttributeException($context, 'int', $attr, $val);
  }

  public static function CoerceToBool(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): bool {
    self::LogCoercion($context, 'bool', $attr, $val);
    if ($val === 'true' || $val === 1 || $val === '1' || $val === $attr) {
      return true;
    }

    if ($val === 'false' || $val === 0 || $val === '0') {
      return false;
    }

    throw new XHPInvalidAttributeException($context, 'bool', $attr, $val);
  }

  public static function CoerceToFloat(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): float {
    self::LogCoercion($context, 'float', $attr, $val);
    if (is_numeric($val)) {
      return (float)$val;
    }

    throw new XHPInvalidAttributeException($context, 'float', $attr, $val);
  }
}



abstract final class XHPAttributeCoercionThree {
  private static XHPAttributeCoercionMode $mode =
    XHPAttributeCoercionMode::THROW_EXCEPTION;

  public static function GetMode(): XHPAttributeCoercionMode {
    return self::$mode;
  }

  public static function SetMode(XHPAttributeCoercionMode $mode): void {
    self::$mode = $mode;
  }

  private static function LogCoercion(
    :x:composable-element $context,
    string $what,
    string $attr,
    mixed $val,
  ): void {
    switch (self::GetMode()) {
      case XHPAttributeCoercionMode::SILENT:
        // Your forward compatibility is bad, and you should feel bad.
        return;
      case XHPAttributeCoercionMode::LOG_DEPRECATION:
        if (is_object($val)) {
          $val_type = get_class($val);
        } else {
          $val_type = gettype($val);
        }
        trigger_error(
          sprintf(
            'Coercing value of type `%s` to `%s` for attribute `%s` of '.
            'element `%s`',
            $val_type,
            $what,
            $attr,
            :xhp::class2element(get_class($context)),
          ),
          E_USER_DEPRECATED,
        );
        return;
      case XHPAttributeCoercionMode::THROW_EXCEPTION:
        throw new XHPInvalidAttributeException($context, $what, $attr, $val);
    }
  }

  public static function CoerceToString(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): string {
    self::LogCoercion($context, 'string', $attr, $val);
    if (($val is int) || ($val is float) || $val instanceof Stringish) {
      return (string)$val;
    }

    throw new XHPInvalidAttributeException($context, 'string', $attr, $val);
  }

  public static function CoerceToInt(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): int {
    self::LogCoercion($context, 'int', $attr, $val);
    if (
      (($val is string) && is_numeric($val) && $val !== '') || ($val is float)
    ) {
      return (int)$val;
    }

    throw new XHPInvalidAttributeException($context, 'int', $attr, $val);
  }

  public static function CoerceToBool(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): bool {
    self::LogCoercion($context, 'bool', $attr, $val);
    if ($val === 'true' || $val === 1 || $val === '1' || $val === $attr) {
      return true;
    }

    if ($val === 'false' || $val === 0 || $val === '0') {
      return false;
    }

    throw new XHPInvalidAttributeException($context, 'bool', $attr, $val);
  }

  public static function CoerceToFloat(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): float {
    self::LogCoercion($context, 'float', $attr, $val);
    if (is_numeric($val)) {
      return (float)$val;
    }

    throw new XHPInvalidAttributeException($context, 'float', $attr, $val);
  }
}




abstract final class XHPAttributeCoercionFour {
  private static XHPAttributeCoercionMode $mode =
    XHPAttributeCoercionMode::THROW_EXCEPTION;

  public static function GetMode(): XHPAttributeCoercionMode {
    return self::$mode;
  }

  public static function SetMode(XHPAttributeCoercionMode $mode): void {
    self::$mode = $mode;
  }

  private static function LogCoercion(
    :x:composable-element $context,
    string $what,
    string $attr,
    mixed $val,
  ): void {
    switch (self::GetMode()) {
      case XHPAttributeCoercionMode::SILENT:
        // Your forward compatibility is bad, and you should feel bad.
        return;
      case XHPAttributeCoercionMode::LOG_DEPRECATION:
        if (is_object($val)) {
          $val_type = get_class($val);
        } else {
          $val_type = gettype($val);
        }
        trigger_error(
          sprintf(
            'Coercing value of type `%s` to `%s` for attribute `%s` of '.
            'element `%s`',
            $val_type,
            $what,
            $attr,
            :xhp::class2element(get_class($context)),
          ),
          E_USER_DEPRECATED,
        );
        return;
      case XHPAttributeCoercionMode::THROW_EXCEPTION:
        throw new XHPInvalidAttributeException($context, $what, $attr, $val);
    }
  }

  public static function CoerceToString(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): string {
    self::LogCoercion($context, 'string', $attr, $val);
    if (($val is int) || ($val is float) || $val instanceof Stringish) {
      return (string)$val;
    }

    throw new XHPInvalidAttributeException($context, 'string', $attr, $val);
  }

  public static function CoerceToInt(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): int {
    self::LogCoercion($context, 'int', $attr, $val);
    if (
      (($val is string) && is_numeric($val) && $val !== '') || ($val is float)
    ) {
      return (int)$val;
    }

    throw new XHPInvalidAttributeException($context, 'int', $attr, $val);
  }

  public static function CoerceToBool(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): bool {
    self::LogCoercion($context, 'bool', $attr, $val);
    if ($val === 'true' || $val === 1 || $val === '1' || $val === $attr) {
      return true;
    }

    if ($val === 'false' || $val === 0 || $val === '0') {
      return false;
    }

    throw new XHPInvalidAttributeException($context, 'bool', $attr, $val);
  }

  public static function CoerceToFloat(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): float {
    self::LogCoercion($context, 'float', $attr, $val);
    if (is_numeric($val)) {
      return (float)$val;
    }

    throw new XHPInvalidAttributeException($context, 'float', $attr, $val);
  }
}



abstract final class XHPAttributeCoercionFive {
  private static XHPAttributeCoercionMode $mode =
    XHPAttributeCoercionMode::THROW_EXCEPTION;

  public static function GetMode(): XHPAttributeCoercionMode {
    return self::$mode;
  }

  public static function SetMode(XHPAttributeCoercionMode $mode): void {
    self::$mode = $mode;
  }

  private static function LogCoercion(
    :x:composable-element $context,
    string $what,
    string $attr,
    mixed $val,
  ): void {
    switch (self::GetMode()) {
      case XHPAttributeCoercionMode::SILENT:
        // Your forward compatibility is bad, and you should feel bad.
        return;
      case XHPAttributeCoercionMode::LOG_DEPRECATION:
        if (is_object($val)) {
          $val_type = get_class($val);
        } else {
          $val_type = gettype($val);
        }
        trigger_error(
          sprintf(
            'Coercing value of type `%s` to `%s` for attribute `%s` of '.
            'element `%s`',
            $val_type,
            $what,
            $attr,
            :xhp::class2element(get_class($context)),
          ),
          E_USER_DEPRECATED,
        );
        return;
      case XHPAttributeCoercionMode::THROW_EXCEPTION:
        throw new XHPInvalidAttributeException($context, $what, $attr, $val);
    }
  }

  public static function CoerceToString(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): string {
    self::LogCoercion($context, 'string', $attr, $val);
    if (($val is int) || ($val is float) || $val instanceof Stringish) {
      return (string)$val;
    }

    throw new XHPInvalidAttributeException($context, 'string', $attr, $val);
  }

  public static function CoerceToInt(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): int {
    self::LogCoercion($context, 'int', $attr, $val);
    if (
      (($val is string) && is_numeric($val) && $val !== '') || ($val is float)
    ) {
      return (int)$val;
    }

    throw new XHPInvalidAttributeException($context, 'int', $attr, $val);
  }

  public static function CoerceToBool(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): bool {
    self::LogCoercion($context, 'bool', $attr, $val);
    if ($val === 'true' || $val === 1 || $val === '1' || $val === $attr) {
      return true;
    }

    if ($val === 'false' || $val === 0 || $val === '0') {
      return false;
    }

    throw new XHPInvalidAttributeException($context, 'bool', $attr, $val);
  }

  public static function CoerceToFloat(
    :x:composable-element $context,
    string $attr,
    mixed $val,
  ): float {
    self::LogCoercion($context, 'float', $attr, $val);
    if (is_numeric($val)) {
      return (float)$val;
    }

    throw new XHPInvalidAttributeException($context, 'float', $attr, $val);
  }
}

/**
 * INCREDIBLY DANGEROUS: Marks an object as a valid child of *any* element,
 * ignoring any child rules.
 *
 * This is useful when migrating to XHP as it allows you to embed non-XHP
 * content, usually in combination with XHPUnsafeRenderable; see MIGRATING.md
 * for more information.
 */
interface XHPAlwaysValidChild {
}

trait XHPAsync implements XHPAwaitable {
  require extends :x:element;

  abstract protected function asyncRender(): Awaitable<XHPRoot>;

  final protected function render(): XHPRoot {
    throw new Exception(
      'You need to call asyncRender() on XHP elements that use XHPAwaitable',
    );
  }
}

/**
 * INCREDIBLY AWESOME: Specify an element as awaitable on render.
 *
 * This allows you to use await inside your XHP objects. For instance, you could
 * fetch data inside your XHP elements using await and the calls to the DB would
 * be batched together when the element is rendered.
 */
interface XHPAwaitable {
  require extends :x:element;
  // protected function asyncRender(): Awaitable<XHPRoot>
}

/**
 * Indicates that any attributes set on an element should be transferred to the
 * element returned from ::render() or ::asyncRender(). This is automatically
 * invoked by :x:element.
 */
interface XHPHasTransferAttributes {
  require extends :x:element;
  public function transferAttributesToRenderedRoot(
    :x:composable-element $root,
  ): void;
}

interface XHPRoot extends XHPChild {
  require extends :x:composable-element;
}

/**
 * INCREDIBLY DANGEROUS: Marks an object as being able to provide an HTML
 * string.
 *
 * This is useful when migrating to XHP for attribute values which are already escaped.
 * If the attribute contains unescaped double quotes, this will not escape them, which will break the runtime behavior.
 */
abstract class XHPUnsafeAttributeValue implements Stringish {
  abstract public function toHTMLString(): string;

  final public function __toString(): string {
    return $this->toHTMLString();
  }
}

/**
 * INCREDIBLY DANGEROUS: Marks an object as being able to provide an HTML
 * string.
 *
 * This is useful when migrating to XHP as it allows you to embed non-XHP
 * content, usually in combination with XHPAlwaysValidChild; see MIGRATING.md
 * for more information.
 */
interface XHPUnsafeRenderable extends XHPChild {
  public function toHTMLString(): string;
}

abstract class :x:composable-elementdupeforlinter extends :xhp {
  private Map<string, mixed> $attributes = Map {};
  private Vector<XHPChild> $children = Vector {};
  private Map<string, mixed> $context = Map {};

  protected function init(): void {
  }

  /**
   * A new :x:composable-element is instantiated for every literal tag
   * expression in the script.
   *
   * The following code:
   * $foo = <foo attr="val">bar</foo>;
   *
   * will execute something like:
   * $foo = new xhp_foo(array('attr' => 'val'), array('bar'));
   *
   * @param $attributes    map of attributes to values
   * @param $children      list of children
   */
  final public function __construct(
    KeyedTraversable<string, mixed> $attributes,
    Traversable<XHPChild> $children,
    dynamic ...$debug_info
  ) {
    parent::__construct($attributes, $children);
    foreach ($children as $child) {
      $this->appendChild($child);
    }

    foreach ($attributes as $key => $value) {
      if (self::isSpreadKey($key)) {
        invariant(
          $value instanceof :x:composable-element,
          "Only XHP can be used with an attribute spread operator",
        );
        $this->spreadElementImpl($value);
      } else {
        $this->setAttribute($key, $value);
      }
    }

    if (:xhp::isChildValidationEnabled()) {
      // There is some cost to having defaulted unused arguments on a function
      // so we leave these out and get them with func_get_args().
      if (C\count($debug_info) >= 2) {
        $this->source = $debug_info[0].':'.$debug_info[1];
      } else {
        $this->source =
          'You have child validation on, but debug information is not being '.
          'passed to XHP objects correctly. Ensure xhp.include_debug is on '.
          'in your PHP configuration. Without this option enabled, '.
          'validation errors will be painful to debug at best.';
      }
    }
    $this->init();
  }

  /**
   * Adds a child to the end of this node. If you give an array to this method
   * then it will behave like a DocumentFragment.
   *
   * @param $child     single child or array of children
   */
  final public function appendChild(mixed $child): this {
    if ($child instanceof Traversable) {
      foreach ($child as $c) {
        $this->appendChild($c);
      }
    } else if ($child instanceof :x:frag) {
      $this->children->addAll($child->getChildren());
    } else if ($child !== null) {
      assert($child instanceof XHPChild);
      $this->children->add($child);
    }
    return $this;
  }

  /**
   * Adds a child to the beginning of this node. If you give an array to this
   * method then it will behave like a DocumentFragment.
   *
   * @param $child     single child or array of children
   */
  final public function prependChild(mixed $child): this {
    // There's no prepend to a Vector, so reverse, append, and reverse agains
    $this->children->reverse();
    $this->appendChild($child);
    $this->children->reverse();
    return $this;
  }

  /**
   * Replaces all children in this node. You may pass a single array or
   * multiple parameters.
   *
   * @param $children  Single child or array of children
   */
  final public function replaceChildren(XHPChild ...$children): this {
    // This function has been micro-optimized
    $new_children = Vector {};
    foreach ($children as $xhp) {
      /* HH_FIXME[4273] bogus "XHPChild always truthy" - FB T41388073 */
    if ($xhp instanceof :x:frag) {
      foreach ($xhp->children as $child) {
        $new_children->add($child);
      }
    } else if (!($xhp instanceof Traversable)) {
      $new_children->add($xhp);
    } else {
      foreach ($xhp as $element) {
        if ($element instanceof :x:frag) {
          foreach ($element->children as $child) {
            $new_children->add($child);
          }
        } else if ($element !== null) {
          $new_children->add($element);
        }
      }
    }
    }
    $this->children = $new_children;
    return $this;
  }

  /**
   * Fetches all direct children of this element that match a particular tag
   * name or category (or all children if none is given)
   *
   * @param $selector   tag name or category (optional)
   * @return array
   */
  final public function getChildren(
    ?string $selector = null,
  ): Vector<XHPChild> {
    if ($selector is string && $selector !== '') {
      $children = Vector {};
      if ($selector[0] == '%') {
        $selector = substr($selector, 1);
        foreach ($this->children as $child) {
          if ($child instanceof :xhp && $child->categoryOf($selector)) {
            $children->add($child);
          }
        }
      } else {
        $selector = :xhp::element2class($selector);
        foreach ($this->children as $child) {
          if (is_a($child, $selector, /* allow strings = */ true)) {
            $children->add($child);
          }
        }
      }
    } else {
      $children = new Vector($this->children);
    }
    return $children;
  }


  /**
   * Fetches the first direct child of the element, or the first child that
   * matches the tag if one is given
   *
   * @param $selector   string   tag name or category (optional)
   * @return            element  the first child node (with the given selector),
   *                             false if there are no (matching) children
   */
  final public function getFirstChild(?string $selector = null): ?XHPChild {
    if ($selector === null) {
      return $this->children->get(0);
    } else if ($selector[0] == '%') {
      $selector = substr($selector, 1);
      foreach ($this->children as $child) {
        if ($child instanceof :xhp && $child->categoryOf($selector)) {
          return $child;
        }
      }
    } else {
      $selector = :xhp::element2class($selector);
      foreach ($this->children as $child) {
        if (is_a($child, $selector, /* allow strings = */ true)) {
          return $child;
        }
      }
    }
    return null;
  }

  /**
   * Fetches the last direct child of the element, or the last child that
   * matches the tag or category if one is given
   *
   * @param $selector  string   tag name or category (optional)
   * @return           element  the last child node (with the given selector),
   *                            false if there are no (matching) children
   */
  final public function getLastChild(?string $selector = null): ?XHPChild {
    $temp = $this->getChildren($selector);
    if ($temp->count() > 0) {
      $count = $temp->count();
      return $temp->at($count - 1);
    }
    return null;
  }

  /**
   * Fetches an attribute from this elements attribute store. If $attr is not
   * defined in the store and is not a data- or aria- attribute an exception
   * will be thrown. An exception will also be thrown if $attr is required and
   * not set.
   *
   * @param $attr      attribute to fetch
   * @return           value
   */
  final public function getAttribute(string $attr): mixed {
    // Return the attribute if it's there
    if ($this->attributes->containsKey($attr)) {
      return $this->attributes->get($attr);
    }

    if (!ReflectionXHPAttribute::IsSpecial($attr)) {
      // Get the declaration
      $decl = static::__xhpReflectionAttribute($attr);

      if ($decl === null) {
        throw new XHPAttributeNotSupportedException($this, $attr);
      } else if ($decl->isRequired()) {
        throw new XHPAttributeRequiredException($this, $attr);
      } else {
        return $decl->getDefaultValue();
      }
    } else {
      return null;
    }
  }

  final public static function __xhpReflectionAttribute(
    string $attr,
  ): ?ReflectionXHPAttribute {
    $map = static::__xhpReflectionAttributes();
    if ($map->containsKey($attr)) {
      return $map[$attr];
    }
    return null;
  }

  <<__MemoizeLSB>>
  final public static function __xhpReflectionAttributes(
  ): Map<string, ReflectionXHPAttribute> {
    $map = Map {};
    $decl = static::__xhpAttributeDeclaration();
    foreach ($decl as $name => $attr_decl) {
      $map[$name] = new ReflectionXHPAttribute($name, $attr_decl);
    }
    return $map;
  }

  <<__MemoizeLSB>>
  final public static function __xhpReflectionChildrenDeclaration(
  ): ReflectionXHPChildrenDeclaration {
    return new ReflectionXHPChildrenDeclaration(
      :xhp::class2element(static::class),
      self::emptyInstance()->__xhpChildrenDeclaration(),
    );
  }

  final public static function __xhpReflectionCategoryDeclaration(
  ): Set<string> {
    return new Set(
      \array_keys(self::emptyInstance()->__xhpCategoryDeclaration()),
    );
  }

  // Work-around to call methods that should be static without a real
  // instance.
  <<__MemoizeLSB>>
  private static function emptyInstance(): this {
    return (
      new \ReflectionClass(static::class)
    )->newInstanceWithoutConstructor();
  }

  final public function getAttributes(): Map<string, mixed> {
    return $this->attributes->toMap();
  }

  /**
   * Determines if a given XHP attribute "key" represents an XHP spread operator
   * in the constructor.
   */
  private static function isSpreadKey(string $key): bool {
    return substr($key, 0, strlen(:xhp::SPREAD_PREFIX)) === :xhp::SPREAD_PREFIX;
  }

  /**
   * Implements the XHP spread operator in expressions like:
   *   <foo attr1="bar" {...$xhp} />
   *
   * This will only copy defined attributes on $xhp to when they are also
   * defined on $this. "Special" data-/aria- attributes will still need to be
   * implicitly transferred, since the typechecker never knows about them.
   *
   * Defaults from $xhp are copied as well, if they are present.
   */
  protected final function spreadElementImpl(
    :x:composable-element $element,
  ): void {
    foreach ($element::__xhpReflectionAttributes() as $attr_name => $attr) {
      $our_attr = static::__xhpReflectionAttribute($attr_name);
      if ($our_attr === null) {
        continue;
      }

      $val = $element->getAttribute($attr_name);
      if ($val === null) {
        continue;
      }

      // If the receiving class has the same attribute and we had a value or
      // a default, then copy it over.
      $this->setAttribute($attr_name, $val);
    }
  }

  /**
   * Sets an attribute in this element's attribute store. If the attribute is
   * not defined in the store and is not a data- or aria- attribute an
   * exception will be thrown. An exception will also be thrown if the
   * attribute value is invalid.
   *
   * @param $attr      attribute to set
   * @param $val       value
   */
  final public function setAttribute(string $attr, mixed $value): this {
    if (!ReflectionXHPAttribute::IsSpecial($attr)) {
      if (:xhp::isAttributeValidationEnabled()) {
        $value = $this->validateAttributeValue($attr, $value);
      }
    } else {
      $value = $value;
    }
    $this->attributes->set($attr, $value);
    return $this;
  }

  /**
   * Takes an array of key/value pairs and adds each as an attribute.
   *
   * @param $attrs    array of attributes
   */
  final public function setAttributes(
    KeyedTraversable<string, mixed> $attrs,
  ): this {
    foreach ($attrs as $key => $value) {
      $this->setAttribute($key, $value);
    }
    return $this;
  }

  /**
   * Whether the attribute has been explicitly set to a non-null value by the
   * caller (vs. using the default set by "attribute" in the class definition).
   *
   * @param $attr attribute to check
   */
  final public function isAttributeSet(string $attr): bool {
    return $this->attributes->containsKey($attr);
  }

  /**
   * Removes an attribute from this element's attribute store. An exception
   * will be thrown if $attr is not supported.
   *
   * @param $attr      attribute to remove
   * @param $val       value
   */
  final public function removeAttribute(string $attr): this {
    if (!ReflectionXHPAttribute::IsSpecial($attr)) {
      if (:xhp::isAttributeValidationEnabled()) {
        $value = $this->validateAttributeValue($attr, null);
      }
    }
    $this->attributes->removeKey($attr);
    return $this;
  }

  /**
   * Sets an attribute in this element's attribute store. Always foregoes
   * validation.
   *
   * @param $attr      attribute to set
   * @param $val       value
   */
  final public function forceAttribute(string $attr, mixed $value): this {
    $this->attributes->set($attr, $value);
    return $this;
  }
  /**
   * Returns all contexts currently set.
   *
   * @return array  All contexts
   */
  final public function getAllContexts(): Map<string, mixed> {
    return $this->context->toMap();
  }

  /**
   * Returns a specific context value. Can include a default if not set.
   *
   * @param string $key     The context key
   * @param mixed $default  The value to return if not set (optional)
   * @return mixed          The context value or $default
   */
  final public function getContext(string $key, mixed $default = null): mixed {
    if ($this->context->containsKey($key)) {
      return $this->context->get($key);
    }
    return $default;
  }

  /**
   * Sets a value that will be automatically passed down through a render chain
   * and can be referenced by children and composed elements. For instance, if
   * a root element sets a context of "admin_mode" = true, then all elements
   * that are rendered as children of that root element will receive this
   * context WHEN RENDERED. The context will not be available before render.
   *
   * @param mixed $key      Either a key, or an array of key/value pairs
   * @param mixed $default  if $key is a string, the value to set
   * @return :xhp           $this
   */
  final public function setContext(string $key, mixed $value): this {
    $this->context->set($key, $value);
    return $this;
  }

  /**
   * Sets a value that will be automatically passed down through a render chain
   * and can be referenced by children and composed elements. For instance, if
   * a root element sets a context of "admin_mode" = true, then all elements
   * that are rendered as children of that root element will receive this
   * context WHEN RENDERED. The context will not be available before render.
   *
   * @param Map $context  A map of key/value pairs
   * @return :xhp         $this
   */
  final public function addContextMap(Map<string, mixed> $context): this {
    $this->context->setAll($context);
    return $this;
  }

  /**
   * Transfers the context but will not overwrite anything. This is done only
   * for rendering because we don't want a parent's context to replace a
   * child's context if they have the same key.
   *
   * @param array $parentContext  The context to transfer
   */
  final protected function __transferContext(
    Map<string, mixed> $parentContext,
  ): void {
    foreach ($parentContext as $key => $value) {
      if (!$this->context->containsKey($key)) {
        $this->context->set($key, $value);
      }
    }
  }

  abstract protected function __flushSubtree(): Awaitable<:x:primitive>;

  /**
   * Defined in elements by the `attribute` keyword. The declaration is simple.
   * There is a keyed array, with each key being an attribute. Each value is
   * an array with 4 elements. The first is the attribute type. The second is
   * meta-data about the attribute. The third is a default value (null for
   * none). And the fourth is whether or not this value is required.
   *
   * Attribute types are suggested by the TYPE_* constants.
   */
  protected static function __xhpAttributeDeclaration(
  ): darray<string, darray<int, mixed>> {
    return darray[];
  }

  /**
   * Defined in elements by the `category` keyword. This is just a list of all
   * categories an element belongs to. Each category is a key with value 1.
   */
  protected function __xhpCategoryDeclaration(): darray<string, int> {
    return darray[];
  }

  /**
   * Defined in elements by the `children` keyword. This returns a pattern of
   * allowed children. The return value is potentially very complicated. The
   * two simplest are 0 and 1 which mean no children and any children,
   * respectively. Otherwise you're dealing with an array which is just the
   * biggest mess you've ever seen.
   */
  protected function __xhpChildrenDeclaration(): mixed {
    return 1;
  }

  /**
   * Throws an exception if $val is not a valid value for the attribute $attr
   * on this element.
   */
  final protected function validateAttributeValue<T>(
    string $attr,
    T $val,
  ): mixed {
    $decl = static::__xhpReflectionAttribute($attr);
    if ($decl === null) {
      throw new XHPAttributeNotSupportedException($this, $attr);
    }
    if ($val === null) {
      return null;
    }
    switch ($decl->getValueType()) {
      case XHPAttributeType::TYPE_STRING:
        if (!($val is string)) {
          $val = XHPAttributeCoercion::CoerceToString($this, $attr, $val);
        }
        break;

      case XHPAttributeType::TYPE_BOOL:
        if (!($val is bool)) {
          $val = XHPAttributeCoercion::CoerceToBool($this, $attr, $val);
        }
        break;

      case XHPAttributeType::TYPE_INTEGER:
        if (!($val is int)) {
          $val = XHPAttributeCoercion::CoerceToInt($this, $attr, $val);
        }
        break;

      case XHPAttributeType::TYPE_FLOAT:
        if (!($val is float)) {
          $val = XHPAttributeCoercion::CoerceToFloat($this, $attr, $val);
        }
        break;

      case XHPAttributeType::TYPE_ARRAY:
        if (!is_array($val)) {
          throw new XHPInvalidAttributeException($this, 'array', $attr, $val);
        }
        break;

      case XHPAttributeType::TYPE_OBJECT:
        $class = $decl->getValueClass();
        if (is_a($val, $class, true)) {
          break;
        }
        /* HH_FIXME[4026] $class as enumname<_> */
        if (enum_exists($class) && $class::isValid($val)) {
          break;
        }
        // Things that are a valid array key without any coercion
        if ($class === 'HH\arraykey') {
          if (($val is int) || ($val is string)) {
            break;
          }
        }
        if ($class === 'HH\num') {
          if (($val is int) || ($val is float)) {
            break;
          }
        }
        if (is_array($val)) {
          try {
            $type_structure = (
              new ReflectionTypeAlias($class)
            )->getResolvedTypeStructure();
            /* HH_FIXME[4110] $type_structure is an array, but should be a
             * TypeStructure<T> */
            TypeAssert\matches_type_structure($type_structure, $val);
            break;
          } catch (ReflectionException $_) {
            // handled below
          } catch (IncorrectTypeException $_) {
            // handled below
          }
        }
        throw new XHPInvalidAttributeException($this, $class, $attr, $val);
        break;

      case XHPAttributeType::TYPE_VAR:
        break;

      case XHPAttributeType::TYPE_ENUM:
        if (!(($val is string) && $decl->getEnumValues()->contains($val))) {
          $enums = 'enum("'.implode('","', $decl->getEnumValues()).'")';
          throw new XHPInvalidAttributeException($this, $enums, $attr, $val);
        }
        break;

      case XHPAttributeType::TYPE_UNSUPPORTED_LEGACY_CALLABLE:
        throw new XHPUnsupportedAttributeTypeException(
          $this,
          'callable',
          $attr,
          'not supported in XHP-Lib 2.0 or higher.',
        );
    }
    return $val;
  }

  /**
   * Validates that this element's children match its children descriptor, and
   * throws an exception if that's not the case.
   */
  final protected function validateChildren(): void {
    $decl = self::__xhpReflectionChildrenDeclaration();
    $type = $decl->getType();
    if ($type === XHPChildrenDeclarationType::ANY_CHILDREN) {
      return;
    }
    if ($type === XHPChildrenDeclarationType::NO_CHILDREN) {
      if ($this->children) {
        throw new XHPInvalidChildrenException($this, 0);
      } else {
        return;
      }
    }
    list($ret, $ii) = $this->validateChildrenExpression(
      $decl->getExpression(),
      0,
    );
    if (!$ret || $ii < count($this->children)) {
      if (($this->children[$ii] ?? null) is XHPAlwaysValidChild) {
        return;
      }
      throw new XHPInvalidChildrenException($this, $ii);
    }
  }

  final private function validateChildrenExpression(
    ReflectionXHPChildrenExpression $expr,
    int $index,
  ): (bool, int) {
    switch ($expr->getType()) {
      case XHPChildrenExpressionType::SINGLE:
        // Exactly once -- :fb-thing
        return $this->validateChildrenRule($expr, $index);
      case XHPChildrenExpressionType::ANY_NUMBER:
        // Zero or more times -- :fb-thing*
        do {
          list($ret, $index) = $this->validateChildrenRule($expr, $index);
        } while ($ret);
        return tuple(true, $index);

      case XHPChildrenExpressionType::ZERO_OR_ONE:
        // Zero or one times -- :fb-thing?
        list($_, $index) = $this->validateChildrenRule($expr, $index);
        return tuple(true, $index);

      case XHPChildrenExpressionType::ONE_OR_MORE:
        // One or more times -- :fb-thing+
        list($ret, $index) = $this->validateChildrenRule($expr, $index);
        if (!$ret) {
          return tuple(false, $index);
        }
        do {
          list($ret, $index) = $this->validateChildrenRule($expr, $index);
        } while ($ret);
        return tuple(true, $index);

      case XHPChildrenExpressionType::SUB_EXPR_SEQUENCE:
        // Specific order -- :fb-thing, :fb-other-thing
        $oindex = $index;
        list($sub_expr_1, $sub_expr_2) = $expr->getSubExpressions();
        list($ret, $index) = $this->validateChildrenExpression(
          $sub_expr_1,
          $index,
        );
        if ($ret) {
          list($ret, $index) = $this->validateChildrenExpression(
            $sub_expr_2,
            $index,
          );
        }
        if ($ret) {
          return tuple(true, $index);
        }
        return tuple(false, $oindex);

      case XHPChildrenExpressionType::SUB_EXPR_DISJUNCTION:
        // Either or -- :fb-thing | :fb-other-thing
        $oindex = $index;
        list($sub_expr_1, $sub_expr_2) = $expr->getSubExpressions();
        list($ret, $index) = $this->validateChildrenExpression(
          $sub_expr_1,
          $index,
        );
        if (!$ret) {
          list($ret, $index) = $this->validateChildrenExpression(
            $sub_expr_2,
            $index,
          );
        }
        if ($ret) {
          return tuple(true, $index);
        }
        return tuple(false, $oindex);
    }
  }

  final private function validateChildrenRule(
    ReflectionXHPChildrenExpression $expr,
    int $index,
  ): (bool, int) {
    switch ($expr->getConstraintType()) {
      case XHPChildrenConstraintType::ANY:
        if ($this->children->containsKey($index)) {
          return tuple(true, $index + 1);
        }
        return tuple(false, $index);

      case XHPChildrenConstraintType::PCDATA:
        if (
          $this->children->containsKey($index) &&
          !($this->children->get($index) instanceof :xhp)
        ) {
          return tuple(true, $index + 1);
        }
        return tuple(false, $index);

      case XHPChildrenConstraintType::ELEMENT:
        $class = $expr->getConstraintString();
        if (
          $this->children->containsKey($index) &&
          is_a($this->children->get($index), $class, true)
        ) {
          return tuple(true, $index + 1);
        }
        return tuple(false, $index);

      case XHPChildrenConstraintType::CATEGORY:
        if (
          !$this->children->containsKey($index) ||
          !($this->children->get($index) instanceof :xhp)
        ) {
          return tuple(false, $index);
        }
        $category = :xhp::class2element($expr->getConstraintString());
        $child = $this->children->get($index);
        assert($child instanceof :xhp);
        $categories = $child->__xhpCategoryDeclaration();
        if (($categories[$category] ?? 0) === 0) {
          return tuple(false, $index);
        }
        return tuple(true, $index + 1);

      case XHPChildrenConstraintType::SUB_EXPR:
        return $this->validateChildrenExpression(
          $expr->getSubExpression(),
          $index,
        );
    }
  }

  /**
   * Returns the human-readable `children` declaration as seen in this class's
   * source code.
   *
   * Keeping this wrapper around reflection, as it fits well with
   * __getChildrenDescription.
   */
  public function __getChildrenDeclaration(): string {
    return self::__xhpReflectionChildrenDeclaration()->__toString();
  }

  /**
   * Returns a description of the current children in this element. Maybe
   * something like this:
   * <div><span>foo</span>bar</div> ->
   * :span[%inline],pcdata
   */
  final public function __getChildrenDescription(): string {
    $desc = array();
    foreach ($this->children as $child) {
      if ($child instanceof :xhp) {
        $tmp = ':'.:xhp::class2element(get_class($child));
        if ($categories = $child->__xhpCategoryDeclaration()) {
          $tmp .= '[%'.implode(',%', array_keys($categories)).']';
        }
        $desc[] = $tmp;
      } else {
        $desc[] = 'pcdata';
      }
    }
    return implode(',', $desc);
  }

  final public function categoryOf(string $c): bool {
    $categories = $this->__xhpCategoryDeclaration();
    if ($categories[$c] ?? null !== null) {
      return true;
    }
    // XHP parses the category string
    $c = str_replace(array(':', '-'), array('__', '_'), $c);
    return ($categories[$c] ?? null) !== null;
  }
}
