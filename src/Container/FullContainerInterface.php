<?php

declare(strict_types=1);

namespace Gacela\Container;

/**
 * @deprecated since 2.0 — every method it added is on {@see ContainerInterface}
 *   now. Kept so code written against it in 1.5 does not have to migrate twice;
 *   typehint ContainerInterface instead. It will be removed in 3.0.
 *
 * @api
 */
interface FullContainerInterface extends ContainerInterface
{
}
