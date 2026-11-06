<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Mapping;

enum MappingKind
{
    /** A scalar member for a simple attribute or a sub-attribute of a single-valued complex attribute. */
    case Field;

    /** A scalar member for one sub-attribute of the element of one type: emails[type eq "work"].value. */
    case Slot;

    /** A member typed with the class of the target resource type: manager. */
    case Reference;

    /** A collection of target objects: members[type eq "User"], groups. */
    case ReferenceCollection;

    /** A collection of element objects whose properties map the sub-attributes: emails with elementClass:. */
    case ElementCollection;

    /** A public method. Read-only; filterable and sortable only with a DQL expression for the Doctrine ORM store. */
    case Computed;
}
