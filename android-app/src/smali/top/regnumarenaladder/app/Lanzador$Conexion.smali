# La conexion con el servicio de Custom Tabs del navegador. Al conectar, abre
# una sesion (warmup + newSession, las dos llamadas del protocolo de Chrome) y
# lanza el sitio con ella.
.class Ltop/regnumarenaladder/app/Lanzador$Conexion;
.super Ljava/lang/Object;
.implements Landroid/content/ServiceConnection;

.field final a:Ltop/regnumarenaladder/app/Lanzador;

.method constructor <init>(Ltop/regnumarenaladder/app/Lanzador;)V
    .registers 2
    invoke-direct {p0}, Ljava/lang/Object;-><init>()V
    iput-object p1, p0, Ltop/regnumarenaladder/app/Lanzador$Conexion;->a:Ltop/regnumarenaladder/app/Lanzador;
    return-void
.end method

.method public onServiceConnected(Landroid/content/ComponentName;Landroid/os/IBinder;)V
    .registers 12
    # v0 callback, v1 data, v2 reply, v3 descriptor, v4 int, v5 sesion, v6-v7 long

    new-instance v0, Ltop/regnumarenaladder/app/Lanzador$Callback;
    invoke-direct {v0}, Ltop/regnumarenaladder/app/Lanzador$Callback;-><init>()V
    const/4 v5, 0x0
    const-string v3, "android.support.customtabs.ICustomTabsService"

    :try_start
    # warmup(0)
    invoke-static {}, Landroid/os/Parcel;->obtain()Landroid/os/Parcel;
    move-result-object v1
    invoke-static {}, Landroid/os/Parcel;->obtain()Landroid/os/Parcel;
    move-result-object v2
    invoke-virtual {v1, v3}, Landroid/os/Parcel;->writeInterfaceToken(Ljava/lang/String;)V
    const-wide/16 v6, 0x0
    invoke-virtual {v1, v6, v7}, Landroid/os/Parcel;->writeLong(J)V
    const/4 v4, 0x1
    const/4 v8, 0x0
    invoke-interface {p2, v4, v1, v2, v8}, Landroid/os/IBinder;->transact(ILandroid/os/Parcel;Landroid/os/Parcel;I)Z
    invoke-virtual {v2}, Landroid/os/Parcel;->readException()V
    invoke-virtual {v1}, Landroid/os/Parcel;->recycle()V
    invoke-virtual {v2}, Landroid/os/Parcel;->recycle()V

    # newSession(callback)
    invoke-static {}, Landroid/os/Parcel;->obtain()Landroid/os/Parcel;
    move-result-object v1
    invoke-static {}, Landroid/os/Parcel;->obtain()Landroid/os/Parcel;
    move-result-object v2
    invoke-virtual {v1, v3}, Landroid/os/Parcel;->writeInterfaceToken(Ljava/lang/String;)V
    invoke-virtual {v1, v0}, Landroid/os/Parcel;->writeStrongBinder(Landroid/os/IBinder;)V
    const/4 v4, 0x2
    invoke-interface {p2, v4, v1, v2, v8}, Landroid/os/IBinder;->transact(ILandroid/os/Parcel;Landroid/os/Parcel;I)Z
    invoke-virtual {v2}, Landroid/os/Parcel;->readException()V
    invoke-virtual {v2}, Landroid/os/Parcel;->readInt()I
    move-result v4
    invoke-virtual {v1}, Landroid/os/Parcel;->recycle()V
    invoke-virtual {v2}, Landroid/os/Parcel;->recycle()V
    if-eqz v4, :lanzar
    move-object v5, v0
    :try_end
    .catch Ljava/lang/Exception; {:try_start .. :try_end} :lanzar

    :lanzar
    iget-object v1, p0, Ltop/regnumarenaladder/app/Lanzador$Conexion;->a:Ltop/regnumarenaladder/app/Lanzador;
    invoke-virtual {v1, v5}, Ltop/regnumarenaladder/app/Lanzador;->abrir(Landroid/os/IBinder;)V
    return-void
.end method

.method public onServiceDisconnected(Landroid/content/ComponentName;)V
    .registers 2
    return-void
.end method
