#!/usr/bin/perl

use strict;
use warnings;
use 5.20.2;
use utf8;

use CGI::Fast;
use CGI;
use HTML::Entities;

$CGI::POST_MAX = 1024 * 1024;  # 1 MiB of form data should be enough for anyone...
$CGI::DISABLE_UPLOADS = 1;

our %config;

sub main {
  %config = %{Data::do('config.txt')};
  while(my $q = CGI::Fast->new) {
    eval {
      my $redirector = Redirector->new($q);
      
      if ($redirector->is_debug) {
        $redirector->print_debug;
      } elsif ($redirector->is_error) {
        $redirector->print_error;
      } elsif ($redirector->is_static) {
        $redirector->print_static;
      } else {
        $redirector->redirect;
      }
    };
    if ($@) {
      chomp($@);
      print $q->header(-status => '400 Internal error');
      printf "<body><p>Internal error: %s</p></body>", encode_entities($@);
    }
  }
}

{ package Redirections;
  # This package loads a set of redirection from a text file and allows to save new redirections.
  
  use File::Spec::Functions 'catfile';
  use FindBin;
  
  our %redir = load();
  
  sub redir_file {
    return catfile($FindBin::Bin, 'redir.txt');
  }

  # returns a hash of redirection.
  sub load {
    open my $fh, '<', redir_file();
    local $/ = "\n";
    my @data = <$fh>;
    close $fh;
    return map { chomp; $1 => $2 if /^(\w+)\s+(\S.+)/ } @data;
  }

  sub save {
    open my $fh, '>', redir_file() or return "Cannot open redirection file: $!";
    for my $k (sort keys %redir) {
      printf $fh "%s    %s\n", $k, $redir{$k};
    }
    close $fh or return "Cannot close redirection file: $!";
    return;
  }
  
  # Return the content that would be saved.
  sub format_data {
    return join("\n", (map { $_.'    '.$redir{$_} } (sort keys %redir)), '');
  }
}  # package Redirections

{ package Redirector;
  # A class that handles the core of the redirection work, parsing the input parameter from
  # the GCI object.

  use HTML::Entities;
  use Safe;
  use Text::Template;
  use URI::Escape;
  
  my $safe = Safe->new();
  $safe->permit_only(qw(:base_core :base_mem :base_loop :base_math :base_orig));
  $safe->deny(qw(tie untie bless));

  sub get_command {
  }

  sub new {
    my ($class, $q) = @_;

    my $self = bless {q => $q}, $class;
    
    #my $command = uri_unescape($q->url(-absolute=> 1, -path => 1));
    #$command = $ARGV[0] if !$command && @ARGV;
    my $prefix = $::config{path_prefix} =~ s{(?<!/)$}{/}r;
    my $url = uri_unescape($q->url(-absolute=> 1, -path => 1));
    if (not $url =~ s/^\Q$prefix\E//) {
      $self->{error_status} = '400 Unexpected prefix';
      $self->{error_text} = 'The URL does not match the expected prefix, the path_prefix option or the RewriteBase in the .htaccess file might be misconfigured.';
      return $self;
    }
    ($self->{base_file}, $self->{path}) = split(qr{/}, $url, 2);

    if (not defined $self->{base_file}) {
      $self->{error_status} = '400 Missing Path';
      $self->{error_text} = 'Missing path in request to apply redirection.';
      return $self;
    }

    my @args = $q->param;
    if (my $error = $q->cgi_error) {
      $self->{error_status} = '400 Could not parse request';
      $self->{error_text} = "Could not parse request: '${error}'.";
      return $self;
    }
    
    my $method = $q->request_method() || 'GET';
    
    if ($self->{base_file} eq 'submit') {
      if ($method ne 'POST') {
        $self->{error_status} = '400 Invalid request method';
        $self->{error_text} = "Invalid request method to submit a new link: '${method}'.";
        return $self;
      }
      $self->validate_submit;
      return $self;
    }
    
    if ($method ne 'GET') {
      $self->{error_status} = '400 Invalid request method';
      $self->{error_text} = "Invalid request method ('${method}'), should be 'GET'.";
      return $self;      
    }
    
    if (Data::exists($self->{base_file}.'.html') && Data::mode($self->{base_file}.'.html') =~ m/\bstatic\b/) {
      $self->{static_file} = $self->{base_file}.'.html';
      return $self;
    } elsif (Data::exists($self->{base_file}) && Data::mode($self->{base_file}) =~ m/\bstatic\b/) {
      $self->{static_file} = $self->{base_file};
      return $self;      
    }

    $self->{redir} = $Redirections::redir{$self->{base_file}};
    if (not defined $self->{redir}) {
      $self->{error_status} = '404 No redirection found';
      $self->{error_text} = "No redirection found for '$self->{base_file}'.";
      return $self;
    }
    
    # This will trigger if the Perl code in a redirection is broken or forbidden by the
    # Safe compartment.
    my $forbidden_redirect = sub {
      $self->{error_status} = '409 Cannot compute redirection';
      $self->{error_text} = "The redirection is invalid or cannot be computed.";      
      return undef;
    };

    $self->{args} = join('&', map { $_.'='.$q->param($_) } @args);
    my %args = map { $_, scalar($q->param($_)) } @args;
    my $tt = Text::Template->new(-type => 'STRING', -source => $self->{redir});
    $self->{dest} = $tt->fill_in(
        -safe => $safe,
        -broken => $forbidden_redirect,
        -hash => {path => $self->{path}, UA => $ENV{HTTP_USER_AGENT}, args => $self->{args}, %args});
    
    return $self;
  }
  
  sub validate_submit {
    my ($self) = @_;
    
    my ($name, $pattern) = (scalar($self->{q}->param('name')), scalar($self->{q}->param('pattern')));
    if (not $name) {
      $self->{error_status} = '400 Missing redirect name';
      $self->{error_text} = "Empty name when submitting a new redirection.";
      return;
    } elsif (not $pattern) {
      $self->{error_status} = '400 Missing redirect pattern';
      $self->{error_text} = "Empty name when submitting a new redirection.";
      return;
    } elsif ($name =~ /\s/) {
      $self->{error_status} = '400 Invalid redirect name';
      $self->{error_text} = "Redirection name cannot contain whitespaces.";
      return;      
    } elsif (length($name) > 100) {
      $self->{error_status} = '400 Invalid redirect name';
      $self->{error_text} = "Redirection name cannot be longer than 100 characters.";
      return;            
    } elsif (length($pattern) > 2000) {
      $self->{error_status} = '400 Invalid redirect pattern';
      $self->{error_text} = "Redirection pattern cannot be longer than 2000 characters.";
      return;            
    }
    
    $Redirections::redir{$name} = $pattern;
    if (my $err = Redirections::save()) {
      $self->{error_status} = '400 Redirect cannot be saved';
      $self->{error_text} = "Failure while saving the new redirection: ${err}.";
      return;                  
    }

    if ($::config{backup_mail}) {
      my $mail = Mail->new(%{$::config{backup_mail}});
      $mail->body("You will find attached a backup of the redirection with the latest addition of the '${name}' redirection.");
      $mail->add_attachment('redir.txt', Redirections::format_data());
      $mail->send;
      if ($mail->error) {
        $self->{error_status} = '400 Cannot send backup mail';
        $self->{error_text} = sprintf "New redirect for $name correctly added but the backup mail could not be sent: %s.", $mail->error;
        return;
      }
    }

    $self->{error_status} = '200 Success';
    $self->{error_text} = "New redirect for ${name} correctly added.";
  }

  sub is_error {
    my ($self) = @_;
    return defined $self->{error_status};
  }

  sub is_debug {
    my ($self) = @_;
    return $self->{q}->param('redirector_debug') // 0;
  }

  sub print_error {
    my ($self) = @_;
    print $self->{q}->header(-status => $self->{error_status});
    my $tt = Text::Template->new(-type => 'STRING', -source => Data::data('error.html.tt'));
    print $tt->fill_in(-hash => {status => encode_entities($self->{error_status}), text => encode_entities($self->{error_text})});
  }
  
  sub is_static {
    my ($self) = @_;
    return $self->{static_file};
  }
  
  sub print_static {
    my ($self) = @_;
    my $mime = Data::mime($self->{static_file});
    my $charset = $mime =~ m/text/ ? 'UTF-8' : '';
    print $self->{q}->header(-type => $mime, -charset => $charset);
    print Data::data($self->{static_file});
  }
  
  { # Package used to render the debug template.
    package DebugTemplate;

    use File::Spec::Functions 'catfile';
    use HTML::Entities;
    use URI::Escape;

    our $self;
    our %config;
    sub uri_to_html { encode_entities(uri_unescape($_[0])); }
  }

  sub print_debug {
    my ($self) = @_;
    print $self->{q}->header;
    my $tt = Text::Template->new(-type => 'STRING', -source => Data::data('debug.html.tt'));
    $DebugTemplate::self = $self;
    %DebugTemplate::config = %::config;
    print $tt->fill_in(
        #-safe => $safe,
        #-broken => $forbidden_redirect,
        -package => 'DebugTemplate');
    return;
  }

  sub redirect {
    my ($self) = @_;
    print $self->{q}->redirect(-uri => $self->{dest}, -status => '302 Found');
  }
}  # package Redirector

{ package Mail;
  # A Class to send emails with attachments.
  #
  # Usage:
  # my $mail = Mail->new(server => 'smtp.example.net', to => 'contact@example.net', from => 'me@example.net', subject => 'Test ');
  # $mail->body('This is a body');
  # $mail->add_attachment('name.txt', 'Some text data');
  # $mail->send;
  # if ($mail->error) {
  #   print "Error: ".$mail->error."\n";
  # }

  use Encode 'encode';
  use Mail::Sendmail 0.75, 'sendmail';
  use MIME::Base64 'encode_base64';
  use MIME::QuotedPrint 'encode_qp';
    
  # %options should be (from => '...', to => '...', subject => '...')
  # and any options from Mail::Sendmail (server, port, auth, ...) except body, message or text.
  sub new {
    my ($class, %options) = @_;
    my $self = bless {}, $class;
    $self->{boundary} = '===='.time().'====';
    $self->{mail} = \%options;
    
    return $self;
  }
  
  # Undef if no error.
  sub error {
    my ($self) = @_;
    return $self->{error};
  }
  
  # Body should be a unicode string. It will be sent as quoted-printable encoded UTF-8 text.
  sub body {
    my ($self, $body) = @_;
    $self->{body} = $body;
  }

  # Name should be an ASCII string to avoid problems (otherwise the file
  # name has to be encoded and we’re not doing it).
  # data must be binary (not a unicode string).
  sub add_attachment {
    my ($self, $name, $data) = @_;
    push @{$self->{attachments}}, [$name, $data];
  }
  
  sub send {
    my ($self) = @_;
    unless ($self->{body}) {
      $self->{error} = 'Cannot send an email without a body';
      return;
    }
    if (not $self->{attachments}) {
      $self->{mail}->{'content-type'} = "text/plain; charset=\"UTF-8\"";
      $self->{mail}->{'content-transfer-encoding'} = 'quoted-printable';
      $self->{mail}->{body} = encode_qp(encode('UTF-8', $self->{body}));
    } else {
      $self->{mail}->{'content-type'} = sprintf 'multipart/mixed; boundary="%s"', $self->{boundary};
      my $boundary = '--'.$self->{boundary};
      my $message = encode_qp(encode('UTF-8', $self->{body}));
      # We don't use indented here-doc, because this requires Perl 5.26 but OVH only has Perl 5.20.2!
      my $body = <<END_OF_BODY;
$boundary
Content-Type: text/plain; charset="UTF-8"
Content-Transfer-Encoding: quoted-printable
Content-Disposition: inline

$message
END_OF_BODY
      for my $attachment (@{$self->{attachments}}) {
        my ($name, $data) = @$attachment;
        $data = encode_base64($data);
        $body .= <<END_OF_ATTACHMENT;
$boundary
Content-Type: application/octet-stream; name="$name"
Content-Transfer-Encoding: base64
Content-Disposition: attachment; filename="$name"

$data
END_OF_ATTACHMENT
      }
      $body .= "${boundary}--\n";
      $self->{mail}->{Message} = $body;
    }
    if (sendmail(%{$self->{mail}})) {
      return 1;
    } else {
      $self->{error} = $Mail::Sendmail::error;
      return;
    }
  }

}  # package Mail

{ package Data;
  # A package similar to Data::Section::Simple but wich does not require any external deps.
  # Expose a %data hash that contains all the inlined file.
  # A section header has the following format:
  #
  # %% file_name [mime_type] # comment
  #
  # The mime_type and command are optional. If the commant is 'gzip' then the data is expected
  # to be a base64 encoded gzipped file that will be expanded.

  use Compress::Zlib 'memGunzip';
  use File::Spec::Functions 'catfile';
  use FindBin;
  use MIME::Base64 'decode_base64';

  my %data;
  my %mime;
  my %mode;

  sub data {
    my ($path) = @_;
    die unless exists $data{$path};
    my $local_path = catfile($FindBin::Bin, $path);
    if (-e $local_path) {
      open my $fh, '<', $local_path;
      local $/;
      my $content = <$fh>;
      close $fh;
      return $content;
    }
    if (exists $mode{$path} and $mode{$path} =~ /\bgzip\b/) {
      $data{$path} = memGunzip(decode_base64($data{$path}));
      $mode{$path} =~ s/\bgzip\b//g;
    }
    return $data{$path};
  }
  
  sub do {
    my ($path) = @_;
    die unless exists $data{$path};
    my $code = data($path);
    die unless $mode{$path} =~ /\bperl\b/;
    my $ret = eval($code);
    die $@ if $@;
    return $ret;
  }

  sub exists {
    return exists $data{$_[0]};
  }
  
  sub lists {
    return sort keys %data;
  }
  
  sub mode {
    return $mode{$_[0]};
  }
  
  sub mime {
    return $mime{$_[0]};
  }

  {
    binmode(DATA, ':utf8');  # Not actually needed as we are already under "use utf8".
    my $file;
    while (<DATA>) {
      #     %%    file_name     [ mime_type ]          #   comment
      if (/^%%\s* (\S+) \s* (?:\[\s*(\S+)\s*]\s*)? (?:\#\s*(.*?)\s*)? $/x) {
        $file = $1;
        $mime{$1} = $2 // 'text/html';
        $mode{$file} = $3 // '';
        next;
      }
      last if /^__END__$/;
      $data{$file} .= $_ if $file && ($data{$file} || ! /^\s*$/);  # We’re skipping initial empty lines.
    }
    close DATA;
  }
  # TODO: decompress the content only when the file is actually used.
  while (my ($f, $m) = each %mode) {
    if ($m =~ /\bgzip\b/) {
     # $data{$f} = memGunzip(decode_base64($data{$f}));
    }
  }
}  # package Data

# main() is called at the end of the file, so that the static initialization
# of all the package is executed first.
main();

# We go back to the Data package to make it easier to read the data from it.
# See: https://perldoc.perl.org/perldata#Special-Literals
package Data;

# We’re using the 'static' tag on the files here to mark the fact that they can be
# served statically by the scripts (other files are for internal use).
__DATA__

%% config.txt # perl

# This is the configuration for the program. You can either edit it here
# directly or you can copy the content of this snippet (until the next starting
# with %%) into a file called config.txt in the script directory and that file
# will take precedance over the content here.
#
# Technically this file is made of Perl source code that should return a hash
# reference with all the configuration option of the program.

{
  # If the script is not served from the root of the domain, you must set here
  # the prefix that will be removed from the paths when the script is called.
  # In this case, you also need to change the 'RewriteBase' directive of the
  # .htaccess file to the same value (for example '/foo').
  #
  # If running behind a reverse-proxy, then the reverse proxy might be able to
  # remove the prefix in the path before calling the script, in which case you
  # should not change this variable.
  path_prefix => '/',

  # This configures a connection to an email server to send a backup of the list
  # of redirections after each addition. You can remove this entire setting if
  # you don’t want to use this feature.
  backup_mail => {
    # The SMTP server to connect to. Defaults to 'localhost' if unspecified.
    server => 'smtp.exampple.com',
    
    # The SMTP port. Default to 25 if unspecified.
    port => 587,
    
    # Authentication parameter. No authentication is performed if omitted.
    # The method field specify the authentication methods that will be
    # attempted. The value set here list all supported method and the field
    # must be set (it can probably be left set to this default value however).
    # The required field specify whether an email can be sent if the
    # authentication fails.
    auth => {
      user => 'user@example.com',
      password => 'the password',
      method => 'DIGEST-MD5 CRAM-MD5 PLAIN LOGIN',
      required => 1
    },

    # The email adress to which the backup is sent.
    to => 'admin@example.com',
    
    # The email adress from which the backup appears to be sent.
    from => 'user@example.com',
    
    # The subject of the backup email.
    subject => 'redirector.example.com backup',
  },
}

%% new.html # static

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Redirector link creation</title>
  <link rel="icon" href="favicon.ico" type="image/x-icon"/>
</head>

<body>
  <div>
    <h1>Redirector link creation</h1>
    
    <form action="/submit" method="post">
      <label for="name">Name:</label>
      <input type="text" id="name" name="name" size=20 required><br>

      <label for="pattern">Pattern:</label>
      <input type="text" id="pattern" name="pattern" size=60 required><br>
      
      <input type="submit" value="Submit">
    </form>
  </div>
  <div><small>Favicon from <a href=\"https://icons8.com/\">ICONS8<a/> (<a href=\"https://iconarchive.com/show/windows-8-icons-by-icons8/Files-Add-Link-icon.html\">source</a>).</small></div>
</body>
</html>

%% error.html.tt

<!doctype html>
<html lang="en">
<head>
  <link rel="icon" href="favicon.ico" type="image/x-icon"/>
</head>
<body>
  <div>
    <h1>{$status}</h1>
    <p>{$text}</p>
  </div>
  <div><small>Favicon from <a href=\"https://icons8.com/\">ICONS8<a/> (<a href=\"https://iconarchive.com/show/windows-8-icons-by-icons8/Files-Add-Link-icon.html\">source</a>).</small></div>
</body>
</html>

%% debug.html.tt

<!doctype html>
<html lang="en">
<head>
  <link rel="icon" href="{catfile($config{path_prefix}, 'favicon.ico')}" type="image/x-icon"/>
</head>
<body><div>
  <p>URL:</p>
  <ul>
    <li>Full: {uri_to_html($self->{q}->url(-full => 1))}</li>
    <li>Absolute: {uri_to_html($self->{q}->url(-absolute => 1))}</li>
    <li>Absolute with path: {uri_to_html($self->{q}->url(-absolute => 1, -path => 1))}</li>
    <li>Absolute with query: {uri_to_html($self->{q}->url(-absolute => 1, -query => 1))}</li>
    <li>From command line: {encode_entities(join(' ', @ARGV))}</li>
  </ul>
  <p>Params:</p>
  <ul> { for $p ($self->{q}->param) {
           $OUT .= sprintf "\n    <li>%s: %s</li>", encode_entities($p), encode_entities(scalar($self->{q}->param($p)));
         }
       }
  </ul>
  <p>Template data:<p>
  <ul>
    <li>base_file: {encode_entities($self->{base_file})}</li>
    <li>template: {encode_entities($self->{redir})}</li>
    <li>path: {encode_entities($self->{path})}</li>
    <li>args: {encode_entities($self->{args})}</li>
    <li>dest: <a href="{encode_entities($self->{dest})}">{encode_entities($self->{dest})}</a></li>
  </ul>
  <p>Program state:<p>
  <ul>
    <li>is_debug: {encode_entities($self->is_debug)}</li>
    <li>is_error: {encode_entities($self->is_error)}</li>
    <li>is_static: {encode_entities($self->is_static)}</li>
  </ul>
{ if ($self->is_error) { Text::Template->new(-type => 'string', -source => <<'EOT')->fill_in();
  <p>Error:</p>
  <ul>
    <li>Status: {$self->{error_status}}</li>
    <li>Text: {$self->{error_text}}</li>
  </ul>
EOT
}}
  <p>Perl:<p>
  <ul>
    <li>version: {$^V}</li>
    <li>$FindBin::Bin: {$FindBin::Bin}</li>
  </ul>
{ if ($self->is_debug > 1) { Text::Template->new(-type => 'string', -source => <<'EOT')->fill_in();
  <p>Redirections:</p>
  <ul> { for $r (sort keys %Redirections::redir) {
           $OUT .= sprintf "\n    <li>%s: %s</li>", encode_entities($r), encode_entities($Redirections::redir{$r});
         }
       }
  </ul>
  <p>Static files:</p>
  <ul> { for $f (Data::lists) {
           $OUT .= "\n    <li>${f}</li>";
         }
       }
  </ul>
EOT
}}
{ if ($self->is_debug > 2) { Text::Template->new(-type => 'string', -source => <<'EOT')->fill_in();
  <p>Env:</p>
  <ul> { for $k (sort keys %ENV) {
           $OUT .= sprintf "\n    <li>%s: %s</li>", encode_entities($k), encode_entities($ENV{$k});
         }
       }
  </ul>
EOT
}}
  </div>
  <div><small>Favicon from <a href=\"https://icons8.com/\">ICONS8<a/> (<a href=\"https://iconarchive.com/show/windows-8-icons-by-icons8/Files-Add-Link-icon.html\">source</a>).</small></div>
</body>
</html> 

%% favicon.ico [image/x-icon] # gzip static

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
JYZ5mX1eQRsz0w7/HzpcbMzeEQEA
