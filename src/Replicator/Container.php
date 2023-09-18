<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.md that was distributed with this source code.
 */

namespace Kdyby\Replicator;

use Closure;
use Iterator;
use Nette;
use ReflectionClass;
use SplObjectStorage;
use Traversable;


/**
 * @author Filip Procházka <filip@prochazka.su>
 * @author Jan Tvrdík
 *
 * @method Nette\Application\UI\Form getForm()
 * @property Nette\Forms\Container $parent
 */
class Container extends Nette\Forms\Container
{

	public bool $forceDefault;

	public int $createDefault;

	public string $containerClass = \Ciki\Forms\Container::class;

	/** @var callable */
	protected $factoryCallback;

	private bool $submittedBy = false;

	private array $created = [];

	private ?array $httpPost = null;


	/**
	 * @throws Nette\InvalidArgumentException
	 */
	public function __construct(callable $factory, int $createDefault = 0, bool $forceDefault = false)
	{
		$this->monitor(Nette\Application\UI\Presenter::class);
		$this->monitor(Nette\Forms\Form::class);

		try {
			$this->factoryCallback = Closure::fromCallable($factory);
		} catch (Nette\InvalidArgumentException $e) {
			$type = is_object($factory) ? 'instanceof ' . get_class($factory) : gettype($factory);
			throw new Nette\InvalidArgumentException(
				'Replicator requires callable factory, ' . $type . ' given.',
				0,
				$e
			);
		}

		$this->createDefault = $createDefault;
		$this->forceDefault = $forceDefault;
	}


	public function setFactory(callable $factory): void
	{
		$this->factoryCallback = Closure::fromCallable($factory);
	}


	/**
	 * Magical component factory
	 */
	protected function attached(Nette\ComponentModel\IComponent $obj): void
	{
		parent::attached($obj);

		if (
			!$obj instanceof Nette\Application\UI\Presenter
			&&
			$this->form instanceof Nette\Application\UI\Form
		) {
			return;
		}

		$this->loadHttpData();
		$this->createDefault();
	}


	/**
	 * @return Iterator|Nette\Forms\Container[]
	 */
	public function getContainers(bool $recursive = false): Iterator
	{
		return $this->getComponents($recursive, \Nette\Forms\Container::class);
	}


	/**
	 * @return Iterator|Nette\Forms\Controls\SubmitButton[]
	 */
	public function getButtons(bool $recursive = false): Iterator
	{
		return $this->getComponents($recursive, Nette\Forms\SubmitterControl::class);
	}


	/**
	 * Magical component factory
	 *
	 * @return Nette\Forms\Container
	 */
	protected function createComponent(string $name): ?Nette\ComponentModel\IComponent
	{
		$container = $this->createContainer();
		$container->currentGroup = $this->currentGroup;
		$this->addComponent($container, $name, $this->getFirstControlName());

		($this->factoryCallback)($container);

		return $this->created[$container->name] = $container;
	}


	private function getFirstControlName(): ?string
	{
		$controls = iterator_to_array($this->getComponents(false, Nette\Forms\Control::class));
		$firstControl = reset($controls);

		return $firstControl ? $firstControl->name : null;
	}


	protected function createContainer(): Nette\Forms\Container
	{
		$class = $this->containerClass;

		return new $class();
	}


	public function isSubmittedBy(): bool
	{
		if ($this->submittedBy) {
			return true;
		}

		foreach ($this->getButtons(true) as $button) {
			if ($button->isSubmittedBy()) {
				return $this->submittedBy = true;
			}
		}

		return false;
	}


	/**
	 * @throws Nette\InvalidArgumentException
	 */
	public function createOne(?string $name = null): Nette\Forms\Container
	{
		if ($name === null) {
			$names = array_keys(iterator_to_array($this->getContainers()));
			$name = $names ? max($names) + 1 : 0;
		}

		// Container is overriden, therefore every request for getComponent($name, false) would return container
		if (isset($this->created[$name])) {
			throw new Nette\InvalidArgumentException("Container with name '$name' already exists.");
		}

		return $this[$name];
	}


	/**
	 * @param array|Traversable $values
	 *
	 * @return Nette\Forms\Container|Container
	 */
	public function setValues($values, bool $erase = false, bool $onlyDisabled = false)
	{
		if (!$this->form->isAnchored() || !$this->form->isSubmitted()) {
			foreach ($values as $name => $value) {
				if ((is_array($value) || $value instanceof Traversable) && !$this->getComponent($name, false)) {
					$this->createOne($name);
				}
			}
		}

		return parent::setValues($values, $erase, $onlyDisabled);
	}


	/**
	 * @internal
	 */
	protected function loadHttpData(): void
	{
		if (!$this->getForm()->isSubmitted()) {
			return;
		}

		foreach ((array) $this->getHttpData() as $name => $value) {
			if ((is_array($value) || $value instanceof Traversable) && !$this->getComponent($name, false)) {
				$this->createOne($name);
			}
		}
	}


	/**
	 * @internal
	 */
	protected function createDefault(): void
	{
		if (!$this->createDefault) {
			return;
		}

		if (!$this->getForm()->isSubmitted()) {
			foreach (range(0, $this->createDefault - 1) as $key) {
				$this->createOne($key);
			}
		} elseif ($this->forceDefault) {
			while (iterator_count($this->getContainers()) < $this->createDefault) {
				$this->createOne();
			}
		}
	}


	/**
	 * @return mixed|null
	 */
	private function getHttpData()
	{
		if ($this->httpPost === null) {
			$path = explode(self::NAME_SEPARATOR, $this->lookupPath(Nette\Forms\Form::class));
			$this->httpPost = Nette\Utils\Arrays::get($this->getForm()->getHttpData(), $path, null);
		}

		return $this->httpPost;
	}


	/**
	 * @throws Nette\InvalidArgumentException
	 */
	public function remove(Nette\ComponentModel\Container $container, bool $cleanUpGroups = false): void
	{
		if ($container->parent !== $this) {
			throw new Nette\InvalidArgumentException('Given component ' . $container->name . ' is not children of ' . $this->name . '.');
		}

		// to check if form was submitted by this one
		foreach ($container->getComponents(true, Nette\Forms\SubmitterControl::class) as $button) {
			/** @var Nette\Forms\Controls\SubmitButton $button */
			if ($button->isSubmittedBy()) {
				$this->submittedBy = true;
				break;
			}
		}

		/** @var Nette\Forms\Controls\BaseControl[] $components */
		$components = $container->getComponents(true);
		$this->removeComponent($container);

		// reflection is required to hack form groups
		$groupRefl = new ReflectionClass(Nette\Forms\ControlGroup::class);
		$controlsProperty = $groupRefl->getProperty('controls');
		$controlsProperty->setAccessible(true);

		// walk groups and clean then from removed components
		$affected = [];
		foreach ($this->getForm()->getGroups() as $group) {
			/** @var SplObjectStorage $groupControls */
			$groupControls = $controlsProperty->getValue($group);

			foreach ($components as $control) {
				if ($groupControls->contains($control)) {
					$groupControls->detach($control);

					if (!in_array($group, $affected, true)) {
						$affected[] = $group;
					}
				}
			}
		}

		// remove affected & empty groups
		if ($cleanUpGroups && $affected) {
			foreach ($this->getForm()->getComponents(false, Nette\Forms\Container::class) as $cont) {
				if ($index = array_search($cont->currentGroup, $affected, true)) {
					unset($affected[$index]);
				}
			}

			/** @var Nette\Forms\ControlGroup[] $affected */
			foreach ($affected as $group) {
				if (!$group->getControls() && in_array($group, $this->getForm()->getGroups(), true)) {
					$this->getForm()->removeGroup($group);
				}
			}
		}
	}


	/**
	 * Counts filled values, filtered by given names
	 */
	public function countFilledWithout(array $components = [], array $subComponents = []): int
	{
		$httpData = array_diff_key((array) $this->getHttpData(), array_flip($components));

		if (!$httpData) {
			return 0;
		}

		$rows = [];
		$subComponents = array_flip($subComponents);
		foreach ($httpData as $item) {
			$filter = function ($value) use (&$filter) {
				if (is_array($value)) {
					return count(array_filter($value, $filter)) > 0;
				}

				return strlen($value);
			};
			$rows[] = array_filter(array_diff_key($item, $subComponents), $filter) ?: false;
		}

		return count(array_filter($rows));
	}


	public function isAllFilled(array $exceptChildren = []): bool
	{
		$components = [];
		foreach ($this->getComponents(false, Nette\Forms\Control::class) as $control) {
			/** @var Nette\Forms\Controls\BaseControl $control */
			$components[] = $control->getName();
		}

		foreach ($this->getContainers() as $container) {
			foreach ($container->getComponents(true, Nette\Forms\SubmitterControl::class) as $button) {
				/** @var Nette\Forms\Controls\SubmitButton $button */
				$exceptChildren[] = $button->getName();
			}
		}

		$filled = $this->countFilledWithout($components, array_unique($exceptChildren));

		return $filled === iterator_count($this->getContainers());
	}


	public function addContainer($name): Nette\Forms\Container
	{
		return $this[$name] = new $this->containerClass;
	}


	public function addComponent(Nette\ComponentModel\IComponent $component, ?string $name, ?string $insertBefore = null): Nette\ComponentModel\IContainer
	{
		$group = $this->currentGroup;
		$this->currentGroup = null;
		parent::addComponent($component, $name, $insertBefore);
		$this->currentGroup = $group;

		return $this;
	}


	private static bool $registered = false;


	public static function register(string $methodName = 'addDynamic'): void
	{
		if (self::$registered) {
			Nette\Forms\Container::extensionMethod(self::$registered, function () {
				throw new Nette\MemberAccessException;
			});
		}

		Nette\Forms\Container::extensionMethod(
			$methodName,
			function (Nette\Forms\Container $_this, string $name, callable $factory, int $createDefault = 0, bool $forceDefault = false) {
				$control = new Container($factory, $createDefault, $forceDefault);
				$control->currentGroup = $_this->currentGroup;

				return $_this[$name] = $control;
			}
		);

		if (self::$registered) {
			return;
		}

		Nette\Forms\Controls\SubmitButton::extensionMethod(
			'addRemoveOnClick',
			function (Nette\Forms\Controls\SubmitButton $_this, ?callable $callback = null) {
				$_this->setValidationScope([]);
				$_this->onClick[] = function (Nette\Forms\Controls\SubmitButton $button) use ($callback) {
					/** @var Container $replicator */
					$replicator = $button->lookup(Container::class);
					if (is_callable($callback)) {
						$callback($replicator, $button->parent);
					}
					if ($form = $button->getForm(false)) {
						$form->onSuccess = [];
					}
					$replicator->remove($button->parent);
				};

				return $_this;
			}
		);

		Nette\Forms\Controls\SubmitButton::extensionMethod(
			'addCreateOnClick',
			function (Nette\Forms\Controls\SubmitButton $_this, bool $allowEmpty = false, ?callable $callback = null) {
				$_this->onClick[] = function (Nette\Forms\Controls\SubmitButton $button) use ($allowEmpty, $callback) {
					/** @var Container $replicator */
					$replicator = $button->lookup(Container::class);
					if (!is_bool($allowEmpty)) {
						$callback = Closure::fromCallable($allowEmpty);
						$allowEmpty = false;
					}
					if ($allowEmpty === true || $replicator->isAllFilled() === true) {
						$newContainer = $replicator->createOne();
						if (is_callable($callback)) {
							$callback($replicator, $newContainer);
						}
					}
					$button->getForm()->onSuccess = [];
				};

				return $_this;
			}
		);

		self::$registered = $methodName;
	}
}
