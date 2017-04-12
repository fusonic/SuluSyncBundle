# SuluSyncBundle

## Installation
With a healthy Sulu installation, install this bundle (packagist package coming soon) and add it to your `AbstractKernel.php`: 

```
...
new Fusonic\SuluSyncBundle\FusonicSuluSyncBundle(),
...
```

## Usage
### Export contents
To export contents, use `app/console sulu:export`. 

### Import contents
To import contents, use `app/console sulu:import <host>`. Downloading assets might take a while, depending on the size of the `uploads` directory. 

To import everything but uploads, use `app/console sulu:import <host> --skip-assets`. This will speed up the import. 
