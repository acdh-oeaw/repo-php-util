FROM tomcat:9-jre8
MAINTAINER Mateusz Żółtak <mateusz.zoltak@oeaw.ac.at>

ENV FEDORA_VERSION="4.7.5" FEDORA_VARIANT=""

RUN cd /usr/local/tomcat/webapps &&\
  wget -nv -O ROOT.war https://github.com/fcrepo4-exts/fcrepo-webapp-plus/releases/download/fcrepo-webapp-plus-${FEDORA_VERSION}/fcrepo-webapp-plus${FEDORA_VARIANT}-${FEDORA_VERSION}.war &&\
  rm -fR /usr/local/tomcat/webapps/ROOT &&\
  mkdir /usr/local/tomcat/webapps/ROOT &&\
  unzip ROOT.war -d ROOT &&\
  rm ROOT.war

EXPOSE 8080
EXPOSE 61616
ENV CATALINA_OPTS="-Dfile.encoding=UTF-8 -Xms1024m -Xmx2048m -XX:MaxHeapFreeRatio=20 -XX:MinHeapFreeRatio=10 -XX:GCTimeRatio=20 -XX:MaxMetaspaceSize=512M" JAVA_OPTS="${JAVA_OPTS} -Djava.net.preferIPv4Stack=true -Dfcrepo.bnode.hash-uri=true -Dfcrepo.streaming.parallel=true -Dfcrepo.modeshape.configuration=classpath:/config/file-simple/repository.json"
