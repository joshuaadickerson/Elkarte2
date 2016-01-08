View consists of 4 components:

* Assets
* Context
* Templates
* Theme


## Assets
Assets consist of CSS, Javascript, Less, and sprites.

## Context
Context should probably be renamed, but a context handler accepts an object (say Board) and then it *decorates* it
with common properties like links.

## Templates
Templates consist of 3 parts: layers, namespaces, and templates. Namespaces are classes. Templates are methods. Layers
are the order in which all of these are called.

## Theme
The theme contains options and 