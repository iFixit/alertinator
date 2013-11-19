Contributor Guide
=================

Building the documentation locally
----------------------------------

There are a number of dependencies required for building the documentation.

Sphinx
^^^^^^

Alertinator's docs are written for compilation with `Sphinx`_, the
documentation system developed for Python's documentation.  We use the `php
domain`_ to help Sphinx understand the docs extracted from our PHP code.

It is recommended you install Sphinx inside a `virtualenv`_ to keep your system
clean::

    [$]> cd docs
    [$]> virtualenv --no-site-packages --distribute env
    [$]> source env/bin/activate
    [$]> pip install -r requirements.txt
    [$]> deactivate
    [$]> cd ..

.. _Sphinx: http://sphinx-doc.org/
.. _php domain: http://pythonhosted.org/sphinxcontrib-phpdomain/
.. _virtualenv: http://www.virtualenv.org/

Dox PHP
^^^^^^^

`Dox PHP`_ is used to extract docstrings from the PHP source and turn them into
reST files suitable for Sphinx.  Please refer to Dox PHP's project page for
installation instructions.

.. _Dox PHP: https://github.com/avalanche123/doxphp

Building and viewing
^^^^^^^^^^^^^^^^^^^^

If all the dependencies are installed, ``build-docs.sh`` should produce a set
of HTML documentation in ``docs/_build/html``.  You can view these in your
browser through any variety of means; one easy way is to use Python::

    [$]> cd doc/_build/_html
    [$]> python -m SimpleHTTPServer

