#!/usr/bin/env perl
# Applies infra/publish-scrub.local.pl (argument 1) to every TEXT file git
# tracks in the current tree - lock files excluded, their hashes must stay
# byte-exact. Used by infra/publish-repo.sh as a filter-branch tree filter.
# Perl rather than grep/xargs: `grep -Z` means "decompress" on macOS, and a
# scrub that silently does nothing is the one failure that must not happen.
use strict;
use warnings;

my $rules = do { local $/; open my $f, '<', $ARGV[0] or die "cannot read $ARGV[0]: $!"; <$f> };
my $scrub = eval "sub { $rules }" or die "bad rules: $@";

my @paths = do { local $/ = "\0"; open my $ls, '-|', 'git', 'ls-files', '-z' or die "git ls-files: $!"; map { chomp; $_ } <$ls> };
for my $path (@paths) {
    next if $path =~ m{(?:^|/)(?:composer\.lock|package-lock\.json|yarn\.lock|pnpm-lock\.yaml)$};
    next unless -f $path && !-l $path && -T $path;
    open my $in, '<:raw', $path or die "read $path: $!";
    my @lines = <$in>;
    close $in;
    my $changed = 0;
    for (@lines) { my $before = $_; $scrub->(); $changed ||= $_ ne $before; }
    next unless $changed;
    open my $out, '>:raw', $path or die "write $path: $!";
    print $out @lines;
    close $out;
}
