# Contributing guide

#### Table of contents

- [Commit messages](#commit-messages)
- [Pull requests](#pull-requests)

## Commit messages

Commit messages lean heavily towards the convention set out by [conventional commits](https://www.conventionalcommits.org).

Each commit message must be in the format that includes a **type**, an optional **scope** and a **subject**:
```
type(scope?): subject  #scope is optional
```

Limit the whole message to 72 characters or less!

Example:

```
build(npm): add react package
```

### Type

Must be one of the following:

* **build**: Changes that affect the build system or external dependencies (example scopes: npm)
* **chore**: Changes that don't really fall under any other type
* **ci**: Changes to the CI configuration files and scripts
* **docs**: Documentation only changes
* **feat**: A new feature
* **fix**: A bug fix
* **perf**: A code change that improves performance
* **refactor**: A code change that neither fixes a bug nor adds a feature
* **revert**: Revert a previous commit
* **task**: Similar to a chore, a small and general change that can be used to cover multiple types
* **test**: Adding missing tests or correcting existing tests

### Scope

A scope may be provided to a commit’s type, to provide additional contextual information and is contained within a parenthesis

### Subject

The subject contains a succinct description of the change:

* use the present tense ("Add feature" not "Added feature")
* use the imperative mood ("Move cursor to..." not "Moves cursor to...")
* don't capitalise the first letter
* don't use a fullstop (.) at the end. <- Not this

<sup>[Back to top ^](#table-of-contents)</sup>

## Pull requests

1. Create a branch from the `main` branch and use the convention: `<feat|fix|task>/name-of-issue`.
2. Once the code is ready to be merged into `main`, open a pull request.
> ⚠️**NOTE:** The title must conform to the conventional commit message format outlined above. This is to ensure the merge commit to the main branch is picked up by the CI and creates an entry in the [CHANGELOG.md](./CHANGELOG.md).
3. To merge the PR, use the "Squash and merge" option. This is to keep the commit history clean and keep the commits on `main` with a 1:1 ratio with previous PRs.

<sup>[Back to top ^](#table-of-contents)</sup>
