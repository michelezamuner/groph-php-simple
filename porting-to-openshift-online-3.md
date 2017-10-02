# Porting Groph to OpenShift Online 3

OpenShift Online 3 allows you to create one free project, which is essentially a namespace for different applications that you can host. These applications can be completely unrelated to each other, or provide different services that are part of the same system. Since the free account only allows four applications, we want to try to stuff everything inside a single application. In particular, instead of wasting another application slot to host a database like MySQL, we'll use a SQLite file database.

With OpenShift Online 3 the command line tool is a must-use. Once a new application, for example a PHP one, has been created from the Web interface, we first need to get the name of the current *pod*, which is the currently deployed container:
```
$ oc get pods
NAME             READY     STATUS        RESTARTS   AGE
groph-1-build    0/1       Completed     0          1d
groph-2-build    0/1       Completed     0          1h
groph-3-build    0/1       Completed     0          53m
groph-4-build    0/1       Completed     0          14m
groph-8-x6h6w    1/1       Running       0          38m
groph-9-4z2xx    0/1       Terminating   0          11m
groph-9-deploy   0/1       Error         0          11m
jgroph-1-build   0/1       Error         0          1d
```

To log in the container:
```
$ oc rsh groph-8-x6h6w
sh-4.2$
```

To copy files into or from the container, we have to use `rsync`:
```
$ oc rsync . groph-8-x6h6w:/usr/share/data/groph
```

The concept of shared data in OpenShift Online 3 is very different from the one in the previous version. Now, we cannot just save some files inside the container, and expect it to be persisted through different builds, because during each build a new container is created from scratch, cloning the source files from the repository, and any other file that was present in the previous container will be destroyed.

The solution is to use *persistent volumes* PV. A persistent volume is a storage area that can be used to store data. The idea is that data should be as decoupled as possible from source code: this is why the two are located into two completely different places. However, we cannot directly use a persistent volume from within our project: we must first create *persistent volumes claims* PVC, which are connections of parts of the persistent volume to a project. This way the same persistent volume can be used by several projects, even though files are not shared among different PVC, so that from each project's perspective it looks like it has its own storage.

First of all, we need to create a new persistent volume claim: this can easily be done from the Web administration, choosing a name for the PVC (for example `groph-data`), and an access mode (for example RWO, Read-Write-Once, creates a volume that can be read and write by a single project). Once this is done, however, the storage is not usable yet, because it's still not configured to be used by the actual containers. To see the current status of the volume configuration, use:
```
$ oc volume dc --all
deploymentconfigs/groph
```

where `dc` stands for "Deployment Configuration". We can see here that there is one namespace available for deployment configurations, which is `deploymentconfigs/groph` (which can also be abbreviated to `dc/groph`), but no actual volume registered.

Now, we want to register the PVC we created above so that it's used by all containers:
```
$ oc volume dc/groph --add --type=persistentVolumeClaim --claim-name=groph-data --mount-path=/usr/share/data
```

here we are asking to create a new registration (`--add`) under the namespace `dc-groph`, of the PVC type, for the claim named `groph-data`, which should be mounted under the path `/usr/share/data`. After having issued this command, make a new build, log into the new container, and you will see the new directory `/usr/share/data`: files created here will be persisted across different builds.

Be ware to remember to specify the PVC type: in fact, if you omit the `--type` param, the default type used will be "temporary directory", which is wiped on every new build.

Now, if we check the volumes configuration again:
```
$ oc volume dc --all
deploymentconfigs/groph
  pvc/groph-data (allocated 1GiB) as volume-nnh1q
      mounted at /usr/share/data
```

we can see that our new configuration has been correctly registered.

Last thing, we need to remember to change the deployment configuration of the groph application to `Recreate`, instead of `Rolling`, so that new builds are always deployed to the same node.


## References:

- https://docs.openshift.com/enterprise/3.0/dev_guide/volumes.html
- https://blog.openshift.com/experimenting-with-persistent-volumes/
- https://stackoverflow.com/questions/46523054/how-to-mount-pvc-on-pod-in-openshift-online-3
