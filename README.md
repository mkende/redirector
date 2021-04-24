# Redirector

A super-simple but powerful URL redirector.

Super-simple because this is a single-file, no installation required GCI script, written in Perl with minimal dependencies, which should allow it to run on most shared hosting (or on any real server).

Powerful because the redirections can embed Perl expressions that have access to the current query context and it is for example possible to redirect an Android client and an iOS client to different URLs.

## Installation

Just copy the `redirector.cgi` file in a directory served by your web server. If you’re using Apache, then also copy the `.htaccess` file. By default the script will take over serving all the content from that directory and the child-directories and it expects to be at the root of the virtual host. If this is not the case, then the `.htaccess` has to be edited (just follow the instructions inside it).

In addition you need to create a password file for the access control.

Finally, open the script and search for `%% config.txt` to find the configuration section of the program. Now either edit it directly in-place or copy the content from this section into a `config.txt` file that you should place next to the script. The configuration is self-documenting.

## Usage

If the script is being served from `redirect.example.com`, you can go to `redirect.example.com/new` to create new redirection. The name of the redirection can be anything that does not contain any whitespace characters. The pattern can be anything at all and should contain the scheme (e.g. `https://`) as it will not be added automatically.

In addition, you can execute snippets of Perl code by placing them within braces in the redirection pattern. These snippets have access to the following variables:

-   `$path`: the path that was passed after the name of the redirection.
-   `$args`: the query string that was passed when the redirection was called (without the leading `?`).
-   For each argument passed in the query string, a variable with the name of the argument is available.
-   `$UA`: The user-agent of the current request.

For example, if a redirection foo is triggered with `https://redirect.example.com/foo/bar/bin?arg1=qux&arg2=baz` then `$path` will be `bar/bin`, `$args` will be `arg1=qux&arg2=baz` (not necessarily in this order), `$arg1` will be `qux`, and `arg2` will be `baz`.

See the section below for some examples. Otherwise, for a full reference on the syntax used by the pattern you can read the [documentation for the `Text::Template` library](https://metacpan.org/pod/Text::Template) that is used internally.

## Example

### Passing query string 

Suppose you want to redirect to pages like `https://target.com/id=123` but the schema of these pages is instable (maybe the ID will soon need to be passed as part of the path or something). You can create a redirection with a pattern like `https://target.com/{$path}` and then call it with `redirect.example.com/123`. If the schema of the target change, the redirect can be fixed without breaking the links using the redirect. And these links can stay as simple as possible (maybe they are printed on some marketing support or embedded somewhere they can’t be updated, etc.).

### Client dependent redirection

You can have a `foo` redirection which the following pattern: `https://redirect.example.com/foo_{$UA =~ m/Android/ ? "android" : "web"}`. And you should then create two other redirections `foo_web` and `foo_android` to handle to two cases (of course the targets could have been inlined directly in the `foo` redirection but this would be less readable).

## Backups

If configured to do so in the `config.txt` file, you will receive a mail with the list of redirection in attachment each time a new redirection is added. This is a very simple backup mechanism for low traffic site (at least low rate of new added redirection).

## Bugs

-   In Fast CGI mode, the redirection file is not reloaded when it changes so different instances will see different data. For now deployment should be done in standard CGI mode.