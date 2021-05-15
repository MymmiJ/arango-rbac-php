# PHP RBAC For ArangoDB

## Why Was This Library Created?

This Library is intended to create a drop-in replacement for the popular OWASP PHP RBAC library but to cater for ArangoDB instead.

The reason a replacement may be desirable is that it is difficult to maintain certain kinds of clustered SQL RBAC databases.

ArangoDB is a natural replacement as it features a lot of Graph traversal tools and optimizations and an extremely intuitive UI. It is therefore easier to manage the tree structures that RBAC creates.

## Who Can Use This Library?

Anyone can use this library.