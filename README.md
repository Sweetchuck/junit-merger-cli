# JUnit merger

[![CircleCI](https://circleci.com/gh/Sweetchuck/junit-merger-cli/tree/1.x.svg?style=svg)](https://circleci.com/gh/Sweetchuck/junit-merger-cli/?branch=1.x)
[![codecov](https://codecov.io/gh/Sweetchuck/junit-merger-cli/branch/1.x/graph/badge.svg?token=HSF16OGPyr)](https://app.codecov.io/gh/Sweetchuck/junit-merger-cli/branch/1.x)

As the name suggests this CLI tool helps to merge two or more JUnit XML files into one. \
Under the hood it uses the [JUnit merger library].


## Usage

By default it reads the input file names from stdIn line by line, and puts the result XML content to the stdOutput. \
So the basic usage:
```
find path/to/junit -type f -name '*.xml' | junit-merger merge:files
```

The input file names also can be provided as arguments. Like this:
```
junit-merger merge:files 1.xml 2.xml
```

The merged XML content can be put into a file by using standard shell redirections. Like this:
```
junit-merger merge:files 1.xml 2.xml > junit.xml
```

Or by using the `--output-file` CLI option. Like this:
```
junit-merger merge:files --output-file='junit.xml' 1.xml 2.xml
```


## Usage - handlers

Handlers are responsible for read and parse the input files and generate the merged XML content. \
To which handler should be used can be controlled by the `--handler` option. Like this:
```
junit-merger merge:files --handler='dom_read_write' 1.xml 2.xml
```
There are three available option


## Usage - handler - dom_read_write

With this handler the input files are parsed with [\DOMDocument] and the output is also generated with it. \
It is safe and reliable, but resource heavy. \
On the other hand this handler recalculates all the `<testsuite tests assertions errors warnings failures skipped time />` attributes. \
This might come handy when multiple `<testcase />` comes from different input files and they are belong to the same `<testsuite />`.


## Usage - handler - dom_read

With this handler the input files are parsed with [\DOMDocument] and the output is generated with string concatenation.


## Usage - handler - substr

The input files have to be in the same format in the terms of the position of the opening `<testsuites>` tag and the closing `</testsuites>` tag. \
These position parsed from the first input file and the same positions will be used for the remaining input files. \
Usually the input files are come from the same source – for example PHPUnit – so their format is identical.


[JUnit merger library]: https://github.com/Sweetchuck/junit-merger
[\DOMDocument]: https://www.php.net/manual/en/book.dom.php
